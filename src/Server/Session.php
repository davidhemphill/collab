<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\ProviderMessage;
use Hemp\Collab\Protocol\Message\QueryAwareness;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Collab\Protocol\Scope;
use Hemp\Yjs\Protocol\Awareness\AwarenessStore;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\ReadOnlyPolicy;
use Hemp\Yjs\Protocol\Sync\SyncAdmission;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;
use Hemp\Yjs\Update\Update;
use Throwable;

/**
 * One client's conversation about one document.
 *
 * Takes a decoded frame, returns a {@see Reception}: what to answer the
 * client that spoke, and what to tell everyone with the document open.
 * Nothing here knows about sockets, event loops, or timers — which is what
 * lets the entire authorization and merge path be tested without a server
 * running, and what lets the daemon be responsible only for moving bytes.
 *
 * The awareness store is shared with every other session for the same
 * document — presence belongs to the document, not to any one connection —
 * while authentication and ownership stay per-session.
 *
 * Everything application-specific arrives through {@see Authenticator} and
 * {@see DocumentStore}. This class never learns what a document is, how a
 * token is signed, or what a role means.
 */
final class Session
{
    private ?string $documentName = null;

    private ?Authenticated $authenticated = null;

    private readonly AwarenessStore $awareness;

    /** @var list<int> Awareness clients this connection introduced. */
    private array $ownedClients = [];

    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly DocumentStore $documents,
        ?AwarenessStore $awareness = null,
    ) {
        $this->awareness = $awareness ?? new AwarenessStore;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated !== null;
    }

    public function scope(): ?Scope
    {
        return $this->authenticated?->scope;
    }

    /**
     * Whatever the authenticator attached to this session — a user, an ID, a
     * claims array. Useful for events and audit trails.
     */
    public function identity(): mixed
    {
        return $this->authenticated?->identity;
    }

    public function documentName(): ?string
    {
        return $this->documentName;
    }

    /**
     * Awareness clients this connection introduced, so a disconnect can retract
     * them. A connection may only ever retract its own.
     *
     * Introduced means the shared store accepted a state for that client from
     * this connection — the same rule Hocuspocus applies. A client restating a
     * peer's presence does not come to own it, or one tab closing would erase
     * another person's cursor.
     *
     * @return list<int>
     */
    public function ownedClients(): array
    {
        return $this->ownedClients;
    }

    /**
     * Handle one frame.
     */
    public function receive(AddressedFrame $frame): Reception
    {
        if ($frame->message instanceof Authentication) {
            return $this->authenticate($frame);
        }

        // Nothing else is answered before authentication. A client that skips
        // the handshake is told to authenticate rather than served.
        if (! $this->isAuthenticated()) {
            return Reception::replies($this->reply($frame, Authentication::tokenRequest()));
        }

        return match (true) {
            $frame->message instanceof Sync => $this->sync($frame),
            $frame->message instanceof Awareness => $this->awareness($frame),
            $frame->message instanceof QueryAwareness => $this->describeAwareness($frame),
            default => Reception::nothing(),
        };
    }

    private function authenticate(AddressedFrame $frame): Reception
    {
        $message = $frame->message;

        // A token *request* travels the other way. A client sending one is
        // confused rather than malicious, but it is not something to act on.
        if (! $message instanceof Authentication || $message->token === null) {
            return Reception::replies($this->reply($frame, Authentication::permissionDenied('A token is required.')));
        }

        try {
            $authenticated = $this->authenticator->authenticate($frame->documentName, $message->token);
        } catch (AuthenticationFailed $failure) {
            return Reception::replies($this->reply($frame, Authentication::permissionDenied($failure->getMessage())));
        }

        $this->authenticated = $authenticated;
        $this->documentName = $frame->documentName;

        $replies = [$this->reply($frame, Authentication::authenticated($authenticated->scope))];

        // Whoever is already here, told to the newcomer at once. Hocuspocus
        // sends this the moment a connection is established; without it a
        // person opening a busy document sees an empty room until each peer
        // happens to renew, which takes up to fifteen seconds.
        $present = $this->awareness->updateFor();

        if (! $present->isEmpty()) {
            $replies[] = $this->reply($frame, new Awareness($present));
        }

        return Reception::replies(...$replies);
    }

    private function sync(AddressedFrame $frame): Reception
    {
        $message = $frame->message->message;
        $resident = $this->documents->load((string) $this->documentName);

        // Asking for state is always allowed; it asserts nothing.
        if ($message instanceof SyncStep1) {
            // Our own question goes first. Hocuspocus writes the answer into
            // the reply it is accumulating and sends the question immediately,
            // so the question reaches the wire first; a client that treats the
            // two as an ordered pair would see a different conversation.
            return Reception::replies(
                $this->reply($frame, new Sync(new SyncStep1($resident->stateVector()))),
                $this->reply($frame, new Sync($message->answer($resident))),
            );
        }

        if (! $message instanceof SyncStep2 && ! $message instanceof SyncUpdate) {
            return Reception::nothing();
        }

        if (! $this->authenticated->permitsWriting()) {
            return $this->admitReadOnly($frame, $message, $resident);
        }

        return $this->merge($frame, $message, $resident);
    }

    /**
     * A read-only client still completes a handshake, so it will answer our
     * sync step one with a step two. Only the step two is judged by content;
     * an unprompted update is refused outright, as Hocuspocus refuses it.
     *
     * @param  SyncStep2|SyncUpdate  $message
     */
    private function admitReadOnly(AddressedFrame $frame, $message, Update $resident): Reception
    {
        $admission = ReadOnlyPolicy::admit($message, $resident);

        return Reception::replies($this->reply(
            $frame,
            $admission === SyncAdmission::IntroducesState ? SyncStatus::rejected() : SyncStatus::accepted(),
        ));
    }

    /**
     * @param  SyncStep2|SyncUpdate  $message
     */
    private function merge(AddressedFrame $frame, $message, Update $resident): Reception
    {
        try {
            $incoming = $message->update()->validate();
        } catch (Throwable) {
            return Reception::replies($this->reply($frame, SyncStatus::rejected()));
        }

        // An update that changes nothing is acknowledged without a write and
        // without a word to anyone else. Every client answers the server's
        // step one on every connect and most of those answers carry nothing
        // new; storing them would put a database round trip on each
        // handshake, and broadcasting them would tell the room about nothing.
        // Hocuspocus is silent here too: Yjs only emits an update event when
        // the document actually changed.
        if ($incoming->isEmpty() || $resident->contains($incoming)) {
            return Reception::replies($this->reply($frame, SyncStatus::accepted()));
        }

        $this->documents->store((string) $this->documentName, $resident->merge($incoming));

        // The change goes to everyone as an Update regardless of whether it
        // arrived as one — a step two is an answer to *this server's*
        // question, and relaying it as a step two would tell every other
        // client that its own question had been answered. The sender is
        // included: Hocuspocus broadcasts to every connection with no origin
        // exclusion, and the echo reaches the wire before the status does.
        //
        // A positive status means the update was validated and merged into
        // the server's state. It promises nothing about durability beyond
        // whatever the store just did — see the acknowledgement contract.
        return Reception::of(
            replies: [$this->reply($frame, SyncStatus::accepted())],
            broadcasts: [$this->reply($frame, new Sync(new SyncUpdate($incoming->encode())))],
        );
    }

    private function awareness(AddressedFrame $frame): Reception
    {
        $change = $this->awareness->apply($frame->message->update, now: $this->now());

        // Ownership follows acceptance, exactly as Hocuspocus tracks it: a
        // connection owns the clients whose states the store accepted from
        // it, and stops owning one the moment its departure is recorded.
        $this->ownedClients = array_values(array_diff(
            array_unique([...$this->ownedClients, ...$change->added]),
            $change->removed,
        ));

        if ($change->isEmpty()) {
            // Nothing was accepted — a stale clock, or a peer restating
            // someone else's presence. y-protocols emits no event for this
            // and Hocuspocus therefore broadcasts nothing. Neither do we,
            // and it matters: every client restates every state it receives,
            // so echoing rejected updates would circulate presence forever.
            return Reception::nothing();
        }

        // Re-encoded from the store rather than relayed from the frame, so
        // what the room hears is what the server accepted — clocks included.
        return Reception::broadcasts($this->reply(
            $frame,
            new Awareness($this->awareness->updateFor($change->clients())),
        ));
    }

    /**
     * Withdraw presence this connection introduced, for the hub to relay when
     * the socket goes away.
     *
     * The removal is applied to the shared store, not just announced: the
     * store keeps the departed client's clock so that a stale message cannot
     * reinstate a cursor whose owner has left.
     *
     * @param  list<int>  $clients
     */
    public function retract(array $clients): AwarenessUpdate
    {
        $removal = $this->awareness->removalFor($clients);

        if (! $removal->isEmpty()) {
            $this->awareness->apply($removal, now: $this->now());
        }

        $this->ownedClients = [];

        return $removal;
    }

    private function describeAwareness(AddressedFrame $frame): Reception
    {
        // Everyone present, to the asker alone. Hocuspocus answers with the
        // document's entire awareness state, and answers even when that state
        // is empty.
        return Reception::replies($this->reply($frame, new Awareness($this->awareness->updateFor())));
    }

    private function reply(AddressedFrame $frame, ProviderMessage $message): AddressedFrame
    {
        return new AddressedFrame($frame->documentName, $message);
    }

    private function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
