<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message as HttpMessage;
use Hemp\Collab\Protocol\CloseEvent;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer as ReactSocketServer;

/**
 * The daemon: a TCP listener that speaks WebSocket and hands frames to the hub.
 *
 * Everything above this is transport-free and tested without a socket. This
 * class is the part that cannot be — so it is kept as thin as possible and
 * does nothing but move bytes: upgrade the connection, frame and deframe, and
 * pass strings through.
 *
 * It does not import anything from Reverb. The two run side by side in the same
 * application and share the container, but not a wire protocol, a connection
 * registry, or a failure domain.
 */
final class SocketServer
{
    private ?ReactSocketServer $socket = null;

    private int $nextConnectionId = 1;

    public function __construct(
        private readonly Hub $hub,
        private readonly int $maxFrameBytes = 16 * 1024 * 1024,
        private readonly ?LoopInterface $loop = null,
    ) {}

    /**
     * Start listening. Returns the address actually bound, which matters when
     * port 0 was requested and the kernel chose one.
     */
    public function listen(string $host, int $port): string
    {
        $loop = $this->loop ?? Loop::get();

        $this->socket = new ReactSocketServer("{$host}:{$port}", [], $loop);
        $this->socket->on('connection', $this->accept(...));

        return (string) $this->socket->getAddress();
    }

    public function stop(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    private function accept(ConnectionInterface $socket): void
    {
        $negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);
        $negotiator->setStrictSubProtocolCheck(false);

        $upgraded = false;
        $buffer = '';

        $socket->on('data', function (string $chunk) use ($socket, $negotiator, &$upgraded, &$buffer): void {
            if ($upgraded) {
                return;
            }

            $buffer .= $chunk;

            // Wait for the whole request head before attempting the upgrade;
            // a handshake split across TCP reads is normal, not an error.
            if (! str_contains($buffer, "\r\n\r\n")) {
                if (strlen($buffer) > 16384) {
                    $socket->end();
                }

                return;
            }

            $request = HttpMessage::parseRequest($buffer);
            $response = $this->handshake($negotiator, $request);

            $socket->write(HttpMessage::toString($response));

            if ($response->getStatusCode() !== 101) {
                $socket->end();

                return;
            }

            $upgraded = true;
            $buffer = '';

            $this->serve($socket);
        });
    }

    /**
     * Perform the RFC 6455 handshake.
     *
     * Ratchet builds its response with an int header value, which guzzle's
     * PSR-7 deprecates. That is not ours to fix and not a reason to stop
     * failing on deprecations we do cause, so exactly that one notice is
     * swallowed here and every other diagnostic is handed back to whatever
     * handler was already installed.
     */
    private function handshake(ServerNegotiator $negotiator, RequestInterface $request): ResponseInterface
    {
        $previous = set_error_handler(
            function (int $severity, string $message, string $file = '', int $line = 0) use (&$previous): bool {
                if ($severity === E_USER_DEPRECATED && str_contains($message, 'MessageInterface::withHeader()')) {
                    return true;
                }

                return $previous !== null && (bool) ($previous)($severity, $message, $file, $line);
            },
        );

        try {
            return $negotiator->handshake($request);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Attach the WebSocket message framing and the connection to the hub.
     */
    private function serve(ConnectionInterface $socket): void
    {
        $connection = new Connection(
            id: 'c'.$this->nextConnectionId++,
            send: fn (string $payload) => $socket->write(
                (new Frame($payload, true, Frame::OP_BINARY))->getContents(),
            ),
            disconnect: function (CloseEvent $event) use ($socket): void {
                $socket->write((new Frame(
                    pack('n', $event->code).$event->reason,
                    true,
                    Frame::OP_CLOSE,
                ))->getContents());

                $socket->end();
            },
            factory: $this->hub->sessions(),
        );

        $this->hub->add($connection);

        $messages = new MessageBuffer(
            new CloseFrameChecker,
            onMessage: function ($message) use ($connection): void {
                $this->hub->receive($connection, (string) $message);
            },
            onControl: function (Frame $frame) use ($socket): void {
                match ($frame->getOpcode()) {
                    Frame::OP_PING => $socket->write(
                        (new Frame($frame->getPayload(), true, Frame::OP_PONG))->getContents(),
                    ),
                    Frame::OP_CLOSE => $socket->end(),
                    default => null,
                };
            },
            exceptionFactory: fn ($msg) => new \UnexpectedValueException($msg),
            // Enforced down here rather than after reassembly: a client that
            // announces a huge frame must be cut off before we buffer it, not
            // once we already hold it in memory.
            maxMessagePayloadSize: $this->maxFrameBytes,
            maxFramePayloadSize: $this->maxFrameBytes,
        );

        $socket->on('data', fn (string $chunk) => $messages->onData($chunk));

        $socket->on('close', function () use ($connection): void {
            $this->hub->remove($connection);
        });

        $socket->on('error', function () use ($socket): void {
            $socket->close();
        });
    }
}
