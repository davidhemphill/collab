<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\Stateless;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\ResidentStore;
use Hemp\Collab\Server\SessionFactory;
use Hemp\Collab\Tests\Support\HostAuthenticator;
use Hemp\Collab\Tests\Support\HostStore;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use React\EventLoop\Loop;

/**
 * The daemon as an application actually starts it.
 *
 * Every layer below has its own tests, but nothing until here runs the path a
 * deployment takes: artisan reads config, resolves the hub out of the
 * container, binds a port, and serves a client. Both bugs found in this package
 * so far lived in exactly that gap — code that every unit test passed over and
 * that would have failed on the first real connection.
 */
beforeEach(function () {
    config()->set('collab.authenticator', HostAuthenticator::class);
    config()->set('collab.store', HostStore::class);
    config()->set('collab.host', '127.0.0.1');
    config()->set('collab.port', freePort());
});

/**
 * Run `collab:start` with the loop already carrying the test's work.
 *
 * The command blocks in the event loop, so the client is scheduled first and
 * the loop is what ends the command: whoever finishes calls Loop::stop() and
 * artisan returns. The watchdog turns a hang into a failure.
 */
function daemon(callable $client, float $seconds = 5.0): string
{
    $address = '127.0.0.1:'.config('collab.port');

    Loop::futureTick(fn () => $client($address));

    $watchdog = Loop::addTimer($seconds, fn () => Loop::stop());

    test()->artisan('collab:start')->assertSuccessful();

    Loop::cancelTimer($watchdog);

    return $address;
}

it('serves a client that connects to the port from config', function () {
    $reply = null;

    daemon(function (string $address) use (&$reply) {
        wsClient($address, function ($socket) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload) use (&$reply) {
            $reply = (new FrameReader)->read($payload);
            Loop::stop();
        });
    });

    expect($reply)->not->toBeNull('The daemon never answered.')
        ->and($reply->message)->toBeInstanceOf(Authentication::class)
        ->and($reply->message->scope)->toBe(Scope::ReadWrite);
});

it('carries an edit between two clients through the container-built hub', function () {
    // A single hub is what makes two sockets the same document. If the provider
    // handed out a hub per resolution this would pass everywhere else and fail
    // exactly here.
    $delivered = null;

    daemon(function (string $address) use (&$delivered) {
        $writer = null;
        $ready = 0;

        $send = function () use (&$writer) {
            $writer->write(clientFrame(
                (new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))))->encode(),
            ));
        };

        // The reader has to be subscribed before the write lands, so the edit
        // only goes out once both handshakes have been answered.
        wsClient($address, function ($socket) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload) use (&$delivered, &$ready, $send) {
            $frame = (new FrameReader)->read($payload);

            if ($frame->message instanceof Authentication) {
                if (++$ready === 2) {
                    $send();
                }

                return;
            }

            $delivered = $frame;
            Loop::stop();
        });

        wsClient($address, function ($socket) use (&$writer) {
            $writer = $socket;

            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload) use (&$ready, $send) {
            if ((new FrameReader)->read($payload)->message instanceof Authentication) {
                if (++$ready === 2) {
                    $send();
                }
            }
        });
    });

    expect($delivered)->not->toBeNull('The edit never reached the other client.')
        ->and($delivered->message)->toBeInstanceOf(Sync::class)
        ->and($delivered->message->message->update()->structCount())
        ->toBe(seeded()->structCount());
});

it('writes the accepted edit to the store before exiting', function () {
    // The acknowledgement no longer waits for the database — the write is
    // debounced — so the daemon's exit path is what guarantees an edit a
    // client was told "accepted" about actually reaches the store. This runs
    // the whole road: accept in memory, stop the loop, drain, then look.
    $acknowledged = false;

    daemon(function (string $address) use (&$acknowledged) {
        wsClient($address, function ($socket) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload, $socket) use (&$acknowledged) {
            $frame = (new FrameReader)->read($payload);

            if ($frame->message instanceof Authentication) {
                $socket->write(clientFrame(
                    (new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))))->encode(),
                ));

                return;
            }

            if ($frame->message instanceof SyncStatus && $frame->message->applied) {
                $acknowledged = true;
                Loop::stop();
            }
        });
    });

    $store = $this->app->make(DocumentStore::class);

    expect($acknowledged)->toBeTrue('The edit was never acknowledged.')
        ->and(isset($store->documents['4711']))
        ->toBeTrue('The daemon exited without flushing the document.')
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());
});

it('binds the port given on the command line over the one in config', function () {
    $configured = config('collab.port');
    $override = freePort();

    $reply = null;

    Loop::futureTick(function () use ($override, &$reply) {
        wsClient("127.0.0.1:{$override}", function ($socket) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload) use (&$reply) {
            $reply = (new FrameReader)->read($payload);
            Loop::stop();
        });
    });

    $watchdog = Loop::addTimer(5.0, fn () => Loop::stop());

    $this->artisan('collab:start', ['--port' => $override])->assertSuccessful();

    Loop::cancelTimer($watchdog);

    expect($override)->not->toBe($configured)
        ->and($reply)->not->toBeNull('The daemon did not bind the port from --port.');
});

it('drops a client that sends an oversized frame without taking the daemon with it', function () {
    // The limit exists because a frame arrives from a socket that may not have
    // authenticated. Enforcing it by letting the exception escape would let any
    // stranger end every other writer's session, so the survivor is the
    // assertion that matters here.
    //
    // The payload is a well-formed message that is merely too big, so size is
    // the only thing that can close this connection. Random bytes would be
    // refused by the frame reader instead and the test would pass either way.
    config()->set('collab.limits.frame_bytes', 1024);

    $survivorReply = null;
    $offenderClosed = false;

    daemon(function (string $address) use (&$survivorReply, &$offenderClosed) {
        wsClient($address, function ($socket) use (&$offenderClosed, $address, &$survivorReply) {
            $socket->on('close', function () use (&$offenderClosed, $address, &$survivorReply) {
                $offenderClosed = true;

                // Only now, so the daemon has already handled the bad frame.
                wsClient($address, function ($next) {
                    $next->write(clientFrame(
                        (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
                    ));
                }, function (string $payload) use (&$survivorReply) {
                    $survivorReply = (new FrameReader)->read($payload);
                    Loop::stop();
                });
            });

            $socket->write(clientFrame(
                (new AddressedFrame('4711', new Stateless(str_repeat('a', 4096))))->encode(),
            ));
        });
    });

    expect($offenderClosed)->toBeTrue('The oversized frame was accepted.')
        ->and($survivorReply)->not->toBeNull('The daemon died with the client that abused it.')
        ->and($survivorReply->message)->toBeInstanceOf(Authentication::class);
});

describe('the resident layer, through a live daemon', function () {
    /**
     * Everything the ResidentStore promises, proven where it actually runs:
     * artisan boots the daemon, the container wires the decorator between the
     * sessions and the host store, real WebSocket frames arrive, and the
     * loop's own timers do the writing. The unit tests inject a clock; here
     * the clock is real, which is the part no unit test can vouch for.
     */

    /** Authenticate, send one edit, and hand the socket over once it is acknowledged. */
    function editThenOn(string $address, callable $acknowledged): void
    {
        wsClient($address, function ($socket) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
            ));
        }, function (string $payload, $socket) use ($acknowledged) {
            $frame = (new FrameReader)->read($payload);

            if ($frame->message instanceof Authentication) {
                $socket->write(clientFrame(
                    (new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))))->encode(),
                ));

                return;
            }

            if ($frame->message instanceof SyncStatus && $frame->message->applied) {
                $acknowledged($socket);
            }
        });
    }

    /** Stop the loop the moment the host store holds the document. */
    function stopOnceStored(object $store, callable $then): void
    {
        $poll = null;

        $poll = Loop::addPeriodicTimer(0.05, function () use ($store, $then, &$poll): void {
            if (isset($store->documents['4711'])) {
                Loop::cancelTimer($poll);
                $then();
                Loop::stop();
            }
        });
    }

    it('writes the edit through the running flush timer, no exit required', function () {
        // The quiet timer is real here: the daemon's own periodic flush must
        // notice it has run out and write, while the connection stays open
        // and the loop keeps turning.
        config()->set('collab.persistence.quiet_seconds', 0.1);

        $store = $this->app->make(DocumentStore::class);
        $writtenWhileRunning = false;

        daemon(function (string $address) use ($store, &$writtenWhileRunning) {
            editThenOn($address, function () use ($store, &$writtenWhileRunning) {
                stopOnceStored($store, function () use (&$writtenWhileRunning) {
                    $writtenWhileRunning = true;
                });
            });
        });

        expect($writtenWhileRunning)->toBeTrue('The flush timer never wrote the document.')
            ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());
    });

    it('flushes the moment the last client disconnects', function () {
        // Both debounce timers are pushed out of reach, so the only thing
        // that can write inside the test's lifetime is the unload path: the
        // socket closing, the hub noticing the room emptied, the resident
        // store flushing before it drops the document.
        config()->set('collab.persistence.quiet_seconds', 3600);
        config()->set('collab.persistence.max_wait_seconds', 3600);

        $store = $this->app->make(DocumentStore::class);
        $writtenWhileRunning = false;

        daemon(function (string $address) use ($store, &$writtenWhileRunning) {
            editThenOn($address, function ($socket) use ($store, &$writtenWhileRunning) {
                $socket->end();

                stopOnceStored($store, function () use (&$writtenWhileRunning) {
                    $writtenWhileRunning = true;
                });
            });
        });

        expect($writtenWhileRunning)->toBeTrue('Disconnecting never flushed the document.')
            ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());
    });

    it('serves the resident state to a newcomer before the database has seen it', function () {
        // The other half of the decoration: load() answered from memory. A
        // second client completes a handshake and receives the first
        // client's edit while the host store is still provably empty —
        // the document exists nowhere but the resident layer.
        config()->set('collab.persistence.quiet_seconds', 3600);
        config()->set('collab.persistence.max_wait_seconds', 3600);

        $store = $this->app->make(DocumentStore::class);
        $served = null;
        $storeEmptyWhenServed = null;

        daemon(function (string $address) use ($store, &$served, &$storeEmptyWhenServed) {
            editThenOn($address, function () use ($address, $store, &$served, &$storeEmptyWhenServed) {
                wsClient($address, function ($socket) {
                    $socket->write(clientFrame(
                        (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
                    ));
                }, function (string $payload, $socket) use ($store, &$served, &$storeEmptyWhenServed) {
                    $frame = (new FrameReader)->read($payload);

                    if ($frame->message instanceof Authentication) {
                        $socket->write(clientFrame(
                            (new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))))->encode(),
                        ));

                        return;
                    }

                    if ($frame->message instanceof Sync && $frame->message->message instanceof SyncStep2) {
                        $served = $frame->message->message->update();
                        $storeEmptyWhenServed = $store->documents === [];
                        Loop::stop();
                    }
                });
            });
        });

        expect($served)->not->toBeNull('The newcomer was never served the document.')
            ->and($served->structCount())->toBe(seeded()->structCount())
            ->and($storeEmptyWhenServed)->toBeTrue('The document had already reached the store, so this proved nothing.');

        // And the exit drain still put it on disk afterwards.
        expect($store->documents['4711']->structCount())->toBe(seeded()->structCount());
    });

    it('comes back from a restart with the document intact', function () {
        // The full circle: an edit is accepted in memory, the daemon shuts
        // down and drains, a fresh daemon loads the blob through the
        // decorator — once — and serves it to a client that was never there.
        daemon(function (string $address) {
            editThenOn($address, fn () => Loop::stop());
        });

        // The daemon process dies; in a test the container survives it, so
        // evict what a new process would rebuild. The host store stays — it
        // is playing the database, which outlives restarts.
        $this->app->forgetInstance(Hub::class);
        $this->app->forgetInstance(ResidentStore::class);
        $this->app->forgetInstance(SessionFactory::class);

        config()->set('collab.port', freePort());

        $served = null;

        daemon(function (string $address) use (&$served) {
            wsClient($address, function ($socket) {
                $socket->write(clientFrame(
                    (new AddressedFrame('4711', Authentication::token('anything')))->encode(),
                ));
            }, function (string $payload, $socket) use (&$served) {
                $frame = (new FrameReader)->read($payload);

                if ($frame->message instanceof Authentication) {
                    $socket->write(clientFrame(
                        (new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))))->encode(),
                    ));

                    return;
                }

                if ($frame->message instanceof Sync && $frame->message->message instanceof SyncStep2) {
                    $served = $frame->message->message->update();
                    Loop::stop();
                }
            });
        });

        expect($served)->not->toBeNull('The restarted daemon never served the document.')
            ->and($served->structCount())->toBe(seeded()->structCount());
    });
});

it('applies the configured awareness limit to a real connection', function () {
    // These keys shipped in the config file for a while while nothing read
    // them, which is worse than not offering them at all: an operator lowering
    // a limit would believe they had.
    config()->set('collab.limits.awareness_clients', 2);

    $closed = false;

    daemon(function (string $address) use (&$closed) {
        wsClient($address, function ($socket) use (&$closed) {
            $socket->on('close', function () use (&$closed) {
                $closed = true;
                Loop::stop();
            });

            $socket->write(clientFrame(
                (new AddressedFrame('4711', new Awareness(new AwarenessUpdate([
                    new AwarenessEntry(1, 1, '{"n":"a"}'),
                    new AwarenessEntry(2, 1, '{"n":"b"}'),
                    new AwarenessEntry(3, 1, '{"n":"c"}'),
                ]))))->encode(),
            ));
        });
    });

    expect($closed)->toBeTrue('An awareness update over the configured limit was accepted.');
});
