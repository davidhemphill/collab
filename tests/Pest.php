<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\AuthenticationFailed;
use Hemp\Collab\Server\Authenticator;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Tests\Laravel\TestCase;
use Hemp\Collab\Tests\Support\Transcripts;
use Hemp\Yjs\Update\Update;
use Ratchet\RFC6455\Messaging\Frame;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * Only the Laravel suite needs an application under it; everything else in this
 * package is deliberately runnable without one.
 */
uses(TestCase::class)->in('Laravel');

/**
 * Assert exact bytes, reporting the difference in hex. A failure that says
 * "0434373131 !== 0434373132" is readable; raw binary in a terminal is not.
 */
expect()->extend('toBeBytes', function (string $expected) {
    expect(bin2hex($this->value))->toBe(bin2hex($expected));

    return $this;
});

/** An authenticator that accepts one token and grants a fixed scope. */
function authenticatorGranting(Scope $scope, string $token = 'good'): Authenticator
{
    return new class($scope, $token) implements Authenticator
    {
        public function __construct(private Scope $scope, private string $token) {}

        public function authenticate(string $documentName, string $token): Authenticated
        {
            if ($token !== $this->token) {
                throw AuthenticationFailed::invalidToken();
            }

            return new Authenticated($this->scope, identity: "user-for-{$documentName}");
        }
    };
}

/** A store that keeps documents in memory. */
function memoryStore(): DocumentStore
{
    return new class implements DocumentStore
    {
        /** @var array<string, Update> */
        public array $documents = [];

        public function load(string $documentName): Update
        {
            return $this->documents[$documentName] ?? Update::empty();
        }

        public function store(string $documentName, Update $update): void
        {
            $this->documents[$documentName] = $update;
        }
    };
}

function seeded(): Update
{
    // A real update, taken from the frame this package records the provider
    // sending. Hand-built bytes would only prove this agrees with itself.
    $frame = (new FrameReader)->read(Transcripts::bytes('sync-update'));

    return $frame->message->message->update();
}

/**
 * Talking to the daemon over a real socket.
 *
 * Everything above the transport is tested without one, which is the point —
 * but it leaves the layer that exists only to touch the network unproven.
 * These drive an actual TCP connection so "the server runs" has evidence.
 */

/**
 * Run the loop until the test finishes or the deadline passes.
 *
 * $done must bind by reference. An arrow function captures by value where it
 * is written, so it would report the state the test started with and turn a
 * working server into a timeout.
 */
function until(callable $done, float $seconds = 5.0): void
{
    $timeout = Loop::addTimer($seconds, fn () => Loop::stop());

    Loop::run();
    Loop::cancelTimer($timeout);

    expect($done())->toBeTrue('The server did not answer within the timeout.');
}

/**
 * A port nothing is listening on.
 *
 * Binding and releasing races with anything else on the machine, but only
 * narrowly, and it beats hard-coding a port that collides with a real daemon.
 */
function freePort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($probe === false) {
        throw new RuntimeException("Could not find a free port: {$error}");
    }

    $port = (int) explode(':', stream_socket_get_name($probe, false))[1];
    fclose($probe);

    return $port;
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

/**
 * Connect, complete the upgrade, then hand each decoded server frame onward.
 *
 * The server never masks its frames, so unwrapping them takes only the length
 * header — enough to avoid pulling a WebSocket client library in just to read
 * the replies.
 */
function wsClient(string $address, callable $onOpen, ?callable $onFrame = null): void
{
    (new Connector)->connect($address)->then(function (ConnectionInterface $socket) use ($address, $onOpen, $onFrame) {
        $buffer = '';
        $upgraded = false;

        $socket->on('data', function (string $chunk) use (&$buffer, &$upgraded, $socket, $onOpen, $onFrame) {
            $buffer .= $chunk;

            if (! $upgraded) {
                if (! str_contains($buffer, "\r\n\r\n")) {
                    return;
                }

                $upgraded = true;
                $buffer = substr($buffer, strpos($buffer, "\r\n\r\n") + 4);

                $onOpen($socket);
            }

            while (strlen($buffer) >= 2) {
                $length = ord($buffer[1]) & 0x7F;
                $offset = 2;

                if ($length === 126) {
                    if (strlen($buffer) < 4) {
                        return;
                    }

                    $length = unpack('n', substr($buffer, 2, 2))[1];
                    $offset = 4;
                } elseif ($length === 127) {
                    return;
                }

                if (strlen($buffer) < $offset + $length) {
                    return;
                }

                $payload = substr($buffer, $offset, $length);
                $buffer = substr($buffer, $offset + $length);

                if ($onFrame !== null) {
                    $onFrame($payload, $socket);
                }
            }
        });

        $socket->write(handshakeFor($address));
    });
}
