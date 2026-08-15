<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Collab\Server\SocketServer;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * The daemon, over a real port.
 *
 * Everything else in this package is tested without a socket, which is the
 * point — but that leaves the one layer that only exists to touch the network
 * unproven. These tests open a TCP connection, perform the WebSocket upgrade by
 * hand, and speak the wire protocol, so "the server runs" is a claim with
 * evidence rather than an assumption.
 */

/**
 * Run the loop until the test finishes or the deadline passes.
 *
 * $done must bind by reference — an arrow function would capture the state the
 * test started with and report a working server as a timeout.
 *
 * A daemon test that hangs is worse than one that fails, so time is bounded
 * here rather than left to the runner.
 */
function until(callable $done, float $seconds = 5.0): void
{
    $timeout = Loop::addTimer($seconds, fn () => Loop::stop());

    Loop::run();
    Loop::cancelTimer($timeout);

    expect($done())->toBeTrue('The server did not answer within the timeout.');
}

function handshakeFor(string $address): string
{
    $key = base64_encode(random_bytes(16));

    return "GET / HTTP/1.1\r\n"
        ."Host: {$address}\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Key: {$key}\r\n"
        ."Sec-WebSocket-Version: 13\r\n\r\n";
}

/** Clients must mask; an unmasked frame is a protocol error the server rejects. */
function clientFrame(string $payload, int $opcode = Frame::OP_BINARY): string
{
    return (new Frame($payload, true, $opcode))->maskPayload()->getContents();
}

function serving(Scope $scope = Scope::ReadWrite): array
{
    $hub = new Hub(new SharedSessionFactory(authenticatorGranting($scope), memoryStore()));
    $server = new SocketServer($hub);
    $address = $server->listen('127.0.0.1', 0);

    return [$server, str_replace('tcp://', '', $address), $hub];
}

it('upgrades a real websocket connection and answers the handshake', function () {
    [$server, $address] = serving();

    $replies = [];
    $upgraded = false;

    (new Connector)->connect($address)->then(function (ConnectionInterface $socket) use ($address, &$replies, &$upgraded) {
        $buffer = '';

        $socket->on('data', function (string $chunk) use (&$buffer, &$replies, &$upgraded, $socket) {
            $buffer .= $chunk;

            if (! $upgraded) {
                if (! str_contains($buffer, "\r\n\r\n")) {
                    return;
                }

                $upgraded = str_contains($buffer, '101');
                $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

                $socket->write(clientFrame(
                    (new AddressedFrame('4711', Authentication::token('good')))->encode(),
                ));

                return;
            }

            // The server never masks, so the payload starts after the two-byte
            // header for anything under 126 bytes — enough for a handshake reply.
            $replies[] = (new FrameReader)->read(substr($buffer, 2));

            $socket->close();
            Loop::stop();
        });

        $socket->write(handshakeFor($address));
    });

    until(function () use (&$upgraded, &$replies) {
        return $upgraded && $replies !== [];
    });

    $server->stop();

    expect($replies[0]->message)->toBeInstanceOf(Authentication::class)
        ->and($replies[0]->message->scope)->toBe(Scope::ReadWrite)
        ->and($replies[0]->documentName)->toBe('4711');
});

it('carries an edit from one real socket to another', function () {
    // The whole stack at once: two sockets, an upgrade each, an authenticated
    // write on one, and the bytes arriving at the other.
    [$server, $address] = serving();

    $delivered = null;
    $ready = 0;

    $open = function (callable $onUpgrade, callable $onFrame) use ($address) {
        (new Connector)->connect($address)->then(function (ConnectionInterface $socket) use ($address, $onUpgrade, $onFrame) {
            $buffer = '';
            $upgraded = false;
            $authenticated = false;

            $socket->on('data', function (string $chunk) use (&$buffer, &$upgraded, &$authenticated, $socket, $onUpgrade, $onFrame) {
                $buffer .= $chunk;

                if (! $upgraded) {
                    if (! str_contains($buffer, "\r\n\r\n")) {
                        return;
                    }

                    $upgraded = true;
                    $buffer = '';

                    $socket->write(clientFrame(
                        (new AddressedFrame('4711', Authentication::token('good')))->encode(),
                    ));

                    return;
                }

                $frame = (new FrameReader)->read(substr($buffer, 2));
                $buffer = '';

                if (! $authenticated) {
                    $authenticated = true;
                    $onUpgrade($socket);

                    return;
                }

                $onFrame($frame, $socket);
            });

            $socket->write(handshakeFor($address));
        });
    };

    $writer = null;

    // The reader connects first so it is already subscribed when the write lands.
    $open(function ($socket) use (&$ready, &$writer) {
        $ready++;

        if ($ready === 2) {
            $writer->write(clientFrame(
                (new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))))->encode(),
            ));
        }
    }, function ($frame) use (&$delivered) {
        $delivered = $frame;
        Loop::stop();
    });

    $open(function ($socket) use (&$ready, &$writer) {
        $writer = $socket;
        $ready++;

        if ($ready === 2) {
            $socket->write(clientFrame(
                (new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))))->encode(),
            ));
        }
    }, fn () => null);

    until(function () use (&$delivered) {
        return $delivered !== null;
    });

    $server->stop();

    expect($delivered->message)->toBeInstanceOf(Sync::class)
        ->and($delivered->message->message->update()->structCount())
        ->toBe(seeded()->structCount());
});

it('answers a ping so an idle connection stays open', function () {
    // Providers ping to keep the socket alive; a server that ignores it gets
    // hung up on partway through a long editing session.
    [$server, $address] = serving();

    $pong = null;

    (new Connector)->connect($address)->then(function (ConnectionInterface $socket) use ($address, &$pong) {
        $buffer = '';
        $upgraded = false;

        $socket->on('data', function (string $chunk) use (&$buffer, &$upgraded, $socket, &$pong) {
            $buffer .= $chunk;

            if (! $upgraded) {
                if (! str_contains($buffer, "\r\n\r\n")) {
                    return;
                }

                $upgraded = true;
                $buffer = '';

                $socket->write(clientFrame('keepalive', Frame::OP_PING));

                return;
            }

            $pong = [ord($buffer[0]) & 0x0F, substr($buffer, 2)];

            $socket->close();
            Loop::stop();
        });

        $socket->write(handshakeFor($address));
    });

    until(function () use (&$pong) {
        return $pong !== null;
    });

    $server->stop();

    expect($pong[0])->toBe(Frame::OP_PONG)
        ->and($pong[1])->toBe('keepalive');
});

it('refuses a plain http request instead of leaving the socket open', function () {
    [$server, $address] = serving();

    $status = null;

    (new Connector)->connect($address)->then(function (ConnectionInterface $socket) use ($address, &$status) {
        $socket->on('data', function (string $chunk) use (&$status, $socket) {
            $status = (int) explode(' ', $chunk)[1];

            $socket->close();
            Loop::stop();
        });

        $socket->write("GET / HTTP/1.1\r\nHost: {$address}\r\n\r\n");
    });

    until(function () use (&$status) {
        return $status !== null;
    });

    $server->stop();

    expect($status)->toBeGreaterThanOrEqual(400);
});
