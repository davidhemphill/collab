<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Closure;
use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\CloseEvent;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\Close;
use Hemp\Yjs\Exception\DecodeException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Routes frames between connections.
 *
 * A {@see Session} decides; this delivers. The session hands back a
 * {@see Reception} that already separates answers from news, so the hub never
 * inspects a frame to work out who should hear it — it sends replies to the
 * connection that spoke and broadcasts to every connection with the document
 * open, the sender included, which is exactly Hocuspocus's delivery: no
 * origin exclusion on updates or awareness.
 *
 * Broadcasts go out before replies. For an accepted update that means the
 * echo reaches the sender ahead of its acknowledgement, which is the order
 * Hocuspocus produces — the broadcast happens while the update is applied,
 * the status is written after.
 *
 * Deliberately transport-free, like everything under it. The socket runtime
 * feeds it strings and it never learns where they came from.
 */
final class Hub
{
    /** @var array<string, Connection> */
    private array $connections = [];

    /**
     * Document name to connection ids.
     *
     * Membership means the connection has authenticated for that document, so
     * everything reachable through {@see peers()} has already proved it may
     * read the document. Nothing else re-checks that, so nothing may add an
     * entry here without asking the session first.
     *
     * @var array<string, array<string, true>>
     */
    private array $subscribers = [];

    private readonly ResidentDocuments $residents;

    public function __construct(
        private readonly SessionFactory $sessions,
        private readonly FrameReader $frames = new FrameReader,
        private readonly LoggerInterface $log = new NullLogger,
        ?ResidentDocuments $residents = null,
    ) {
        $this->residents = $residents ?? new ResidentDocuments;
    }

    /**
     * The builder a connection makes its per-document sessions with.
     *
     * The hub wraps the host's factory rather than handing it over, because
     * the hub owns the one thing a session cannot make for itself: the
     * document's shared awareness store.
     *
     * @return Closure(string): Session
     */
    public function sessions(): Closure
    {
        return fn (string $documentName): Session => ($this->sessions)(
            $documentName,
            $this->residents->awarenessFor($documentName),
        );
    }

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id] = $connection;
    }

    /**
     * Handle one frame from one connection.
     *
     * A frame that cannot be decoded closes the connection rather than being
     * skipped: the reader consumes a whole frame or nothing, so a failure means
     * we have lost the client's framing and anything after it is guesswork.
     */
    public function receive(Connection $connection, string $bytes): void
    {
        try {
            $frame = $this->frames->read($bytes);
        } catch (DecodeException $failure) {
            $connection->close(CloseEvent::policyViolation($failure->getMessage()));
            $this->remove($connection);

            return;
        }

        $session = $connection->sessionFor($frame->documentName);

        try {
            $reception = $session->receive($frame);
        } catch (DecodeException $failure) {
            $connection->close(CloseEvent::policyViolation($failure->getMessage()));
            $this->remove($connection);

            return;
        } catch (Throwable $failure) {
            // The host's authenticator or store threw. That is one document's
            // bad afternoon, not the server's: without this the exception
            // leaves the event loop and takes every other connection, on every
            // other document, down with it. The client is told to come back,
            // and its unsent work returns with it on the next handshake.
            $this->log->error('Collaboration session failed for {document}: {message}', [
                'document' => $frame->documentName,
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);

            $connection->close(CloseEvent::internalError());
            $this->remove($connection);

            return;
        }

        // Joining the document is what makes a connection reachable, so it
        // waits for the handshake. Subscribing any earlier would mean that
        // naming a document was enough to receive every edit made to it,
        // without a token ever being presented.
        if ($session->isAuthenticated()) {
            $this->subscribers[$frame->documentName][$connection->id] = true;
        }

        foreach ($reception->broadcasts as $broadcast) {
            foreach ($this->peers($frame->documentName) as $peer) {
                $peer->send($broadcast);
            }
        }

        $connection->sendAll($reception->replies);

        if ($frame->message instanceof Close) {
            $this->leave($connection, $frame->documentName);
        }
    }

    /**
     * Everyone with this document open — including, unless excluded, whoever
     * is asking.
     *
     * @return list<Connection>
     */
    public function peers(string $documentName, ?Connection $except = null): array
    {
        $peers = [];

        foreach (array_keys($this->subscribers[$documentName] ?? []) as $id) {
            $peer = $this->connections[$id] ?? null;

            if ($peer !== null && $peer !== $except && ! $peer->isClosed()) {
                $peers[] = $peer;
            }
        }

        return $peers;
    }

    /**
     * A client leaving one document while staying connected for others.
     */
    public function leave(Connection $connection, string $documentName): void
    {
        $this->retractAwareness($connection, $documentName);

        unset($this->subscribers[$documentName][$connection->id]);
        $connection->forget($documentName);

        // The last one out unloads the document. Presence dies with it, which
        // is what presence means; anything durable went through the store.
        if ($this->subscriberCount($documentName) === 0) {
            unset($this->subscribers[$documentName]);
            $this->residents->unload($documentName);
        }
    }

    /**
     * A socket going away.
     */
    public function remove(Connection $connection): void
    {
        foreach ($connection->documentNames() as $documentName) {
            $this->leave($connection, $documentName);
        }

        unset($this->connections[$connection->id]);
    }

    /**
     * Drop presence that has gone quiet, and tell each document's room.
     *
     * y-protocols expires a client thirty seconds after its last message and
     * checks every three; Hocuspocus inherits that timer through its Awareness
     * instance, and the daemon drives this one on the same cadence. Without
     * it, a cursor whose socket died without closing — a laptop lid, a lost
     * network — would stand in the document until the operating system gave
     * up on the connection.
     */
    public function expireAwareness(?int $now = null): void
    {
        $now ??= (int) (microtime(true) * 1000);

        foreach ($this->residents->each() as $documentName => $awareness) {
            $change = $awareness->expire($now);

            if ($change->removed === []) {
                continue;
            }

            $frame = new AddressedFrame($documentName, new Awareness($awareness->updateFor($change->removed)));

            foreach ($this->peers($documentName) as $peer) {
                $peer->send($frame);
            }
        }
    }

    /**
     * Tell the document that this connection's presence is gone.
     *
     * Only the clients it introduced. A connection retracting someone else's
     * presence would let one client evict another from the cursor list.
     */
    private function retractAwareness(Connection $connection, string $documentName): void
    {
        if (! $connection->hasSessionFor($documentName)) {
            return;
        }

        $owned = $connection->sessionFor($documentName)->ownedClients();

        if ($owned === []) {
            return;
        }

        $removal = $connection->sessionFor($documentName)->retract($owned);

        if ($removal->isEmpty()) {
            return;
        }

        $frame = new AddressedFrame($documentName, new Awareness($removal));

        foreach ($this->peers($documentName, $connection) as $peer) {
            $peer->send($frame);
        }
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    public function subscriberCount(string $documentName): int
    {
        return count($this->subscribers[$documentName] ?? []);
    }
}
