<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\CloseEvent;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\Close;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Yjs\Exception\DecodeException;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;

/**
 * Routes frames between connections.
 *
 * A {@see Session} answers the client that spoke. The other half of a
 * collaboration server is telling everyone *else* — and that is all this does:
 * decode, hand to the right session, send its replies back, and fan the
 * accepted change out to the document's other connections.
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

    public function __construct(
        private readonly SessionFactory $sessions,
        private readonly FrameReader $frames = new FrameReader,
    ) {}

    /**
     * The factory a connection builds its per-document sessions with.
     */
    public function sessions(): SessionFactory
    {
        return $this->sessions;
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
            $replies = $session->receive($frame);
        } catch (DecodeException $failure) {
            $connection->close(CloseEvent::policyViolation($failure->getMessage()));
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

        $connection->sendAll($replies);

        $this->fanOut($connection, $frame, $replies);

        if ($frame->message instanceof Close) {
            $this->leave($connection, $frame->documentName);
        }
    }

    /**
     * Pass an accepted change on to everyone else in the document.
     *
     * Only changes that were accepted travel. An update the session refused —
     * because the sender may not write, or because it did not decode — must not
     * reach anyone, or a read-only client could broadcast through a server that
     * declined to store what it sent.
     *
     * @param  list<AddressedFrame>  $replies
     */
    private function fanOut(Connection $sender, AddressedFrame $frame, array $replies): void
    {
        // A connection speaks for nobody until it has authenticated for this
        // document. An update is already covered by the accepted check below,
        // since a session that refused to merge sends back no status. Awareness
        // carries no status at all, so without this a stranger could put a
        // cursor under any name in front of everyone in the document.
        if (! $sender->sessionFor($frame->documentName)->isAuthenticated()) {
            return;
        }

        $message = $frame->message;

        if ($message instanceof Sync) {
            $inner = $message->message;

            // A step two answers our own step one, so it carries state the
            // sender thinks we lack — relay it like any other update. A step
            // one is a question and has nothing to relay.
            if (! $inner instanceof SyncStep2 && ! $inner instanceof SyncUpdate) {
                return;
            }

            if (! $this->wasAccepted($replies)) {
                return;
            }
        } elseif (! $message instanceof Awareness) {
            return;
        }

        // Awareness goes back to the sender too, which looks redundant and is
        // load-bearing: @hocuspocus/provider force-closes a socket it has heard
        // nothing on for messageReconnectTimeout (30s), counting only frames it
        // received — "not even your own Awareness updates", as its own source
        // puts it. A lone editor sends awareness every 15s and, without the
        // echo, receives nothing between edits, so the client drops and
        // reconnects on a loop. Hocuspocus itself broadcasts awareness to every
        // connection including the origin (handleAwarenessUpdate iterates
        // getConnections() with no exclusion); matching that is what keeps a
        // solo session alive. Document updates stay peers-only: echoing a
        // client's own update back is pure waste, and sync already answers it.
        $recipients = $message instanceof Awareness
            ? $this->peers($frame->documentName)
            : $this->peers($frame->documentName, $sender);

        foreach ($recipients as $peer) {
            $peer->send($frame);
        }
    }

    /**
     * @param  list<AddressedFrame>  $replies
     */
    private function wasAccepted(array $replies): bool
    {
        foreach ($replies as $reply) {
            if ($reply->message instanceof SyncStatus) {
                return $reply->message->applied;
            }
        }

        // No status at all means the session did not treat it as an update to
        // accept — an unauthenticated client, for instance. Nothing to relay.
        return false;
    }

    /**
     * Everyone else with this document open.
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
