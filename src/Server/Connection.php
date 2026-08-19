<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Closure;
use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\CloseEvent;

/**
 * One client socket, and the documents it has open on that socket.
 *
 * A provider multiplexes every document it is editing over a single
 * connection, so a connection is not a session — it holds one {@see Session}
 * per document, each with its own authentication. Authenticating for one
 * document grants nothing for another.
 *
 * Sending is a closure rather than a socket, so the whole routing and
 * broadcast layer can be exercised without opening a port.
 */
final class Connection
{
    /** @var array<string, Session> */
    private array $sessions = [];

    private ?CloseEvent $closed = null;

    /**
     * @param  Closure(string): void  $send  Writes one frame to the client.
     * @param  Closure(CloseEvent): void  $disconnect
     * @param  Closure(string): Session  $factory  Builds the session for a
     *                                             document; comes from the hub,
     *                                             which supplies the document's
     *                                             shared awareness store.
     */
    public function __construct(
        public readonly string $id,
        private readonly Closure $send,
        private readonly Closure $disconnect,
        private readonly Closure $factory,
    ) {}

    /**
     * The session for a document, created on first sight.
     */
    public function sessionFor(string $documentName): Session
    {
        return $this->sessions[$documentName] ??= ($this->factory)($documentName);
    }

    public function hasSessionFor(string $documentName): bool
    {
        return isset($this->sessions[$documentName]);
    }

    /**
     * The documents open on this connection.
     *
     * Cast back to string because PHP silently makes an integer key out of a
     * numeric one, and a document named "4711" would otherwise come back as an
     * int and fail every string parameter it is passed to.
     *
     * @return list<string>
     */
    public function documentNames(): array
    {
        return array_map(strval(...), array_keys($this->sessions));
    }

    public function forget(string $documentName): void
    {
        unset($this->sessions[$documentName]);
    }

    public function send(AddressedFrame $frame): void
    {
        if ($this->closed === null) {
            ($this->send)($frame->encode());
        }
    }

    /**
     * @param  iterable<AddressedFrame>  $frames
     */
    public function sendAll(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->send($frame);
        }
    }

    public function close(CloseEvent $event): void
    {
        if ($this->closed !== null) {
            return;
        }

        $this->closed = $event;
        ($this->disconnect)($event);
    }

    public function isClosed(): bool
    {
        return $this->closed !== null;
    }

    public function closedWith(): ?CloseEvent
    {
        return $this->closed;
    }
}
