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
 * Takes a decoded frame, returns the frames to send back. Nothing here knows
 * about sockets, event loops, or timers — which is what lets the entire
 * authorization and merge path be tested without a server running, and what
 * will let the eventual daemon be responsible only for moving bytes.
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
    ) {
        $this->awareness = new AwarenessStore;
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
     * @return list<int>
     */
    public function ownedClients(): array
    {
        return $this->ownedClients;
    }

    /**
     * Handle one frame and return what to send back.
     *
     * @return list<AddressedFrame>
     */
    public function receive(AddressedFrame $frame): array
    {
        if ($frame->message instanceof Authentication) {
            return $this->authenticate($frame);
        }

        // Nothing else is answered before authentication. A client that skips
        // the handshake is told to authenticate rather than served.
        if (! $this->isAuthenticated()) {
            return [$this->reply($frame, Authentication::tokenRequest())];
        }

        return match (true) {
            $frame->message instanceof Sync => $this->sync($frame),
            $frame->message instanceof Awareness => $this->awareness($frame),
            $frame->message instanceof QueryAwareness => $this->describeAwareness($frame),
            default => [],
        };
    }

    /**
     * @return list<AddressedFrame>
     */
    private function authenticate(AddressedFrame $frame): array
    {
        $message = $frame->message;

        // A token *request* travels the other way. A client sending one is
        // confused rather than malicious, but it is not something to act on.
        if (! $message instanceof Authentication || $message->token === null) {
            return [$this->reply($frame, Authentication::permissionDenied('A token is required.'))];
        }

        try {
            $authenticated = $this->authenticator->authenticate($frame->documentName, $message->token);
        } catch (AuthenticationFailed $failure) {
            return [$this->reply($frame, Authentication::permissionDenied($failure->getMessage()))];
        }

        $this->authenticated = $authenticated;
        $this->documentName = $frame->documentName;

        return [$this->reply($frame, Authentication::authenticated($authenticated->scope))];
    }

    /**
     * @return list<AddressedFrame>
     */
    private function sync(AddressedFrame $frame): array
    {
        $message = $frame->message->message;
        $resident = $this->documents->load((string) $this->documentName);

        // Asking for state is always allowed; it asserts nothing.
        if ($message instanceof SyncStep1) {
            return [$this->reply($frame, new Sync($message->answer($resident)))];
        }

        if (! $message instanceof SyncStep2 && ! $message instanceof SyncUpdate) {
            return [];
        }

        if (! $this->authenticated->permitsWriting()) {
            return $this->admitReadOnly($frame, $message, $resident);
        }

        return $this->merge($frame, $message, $resident);
    }

    /**
     * A read-only client still completes a handshake, so it will answer our
     * sync step one with a step two. Only updates that would change something
     * are refused.
     *
     * @param  SyncStep2|SyncUpdate  $message
     * @return list<AddressedFrame>
     */
    private function admitReadOnly(AddressedFrame $frame, $message, Update $resident): array
    {
        $admission = ReadOnlyPolicy::admit($message, $resident);

        return [$this->reply(
            $frame,
            $admission === SyncAdmission::IntroducesState ? SyncStatus::rejected() : SyncStatus::accepted(),
        )];
    }

    /**
     * @param  SyncStep2|SyncUpdate  $message
     * @return list<AddressedFrame>
     */
    private function merge(AddressedFrame $frame, $message, Update $resident): array
    {
        try {
            $incoming = $message->update()->validate();
        } catch (Throwable) {
            return [$this->reply($frame, SyncStatus::rejected())];
        }

        $this->documents->store((string) $this->documentName, $resident->merge($incoming));

        // A positive status means the update was validated and merged into the
        // server's state. It promises nothing about durability beyond whatever
        // the store just did — see the acknowledgement contract in the plan.
        return [$this->reply($frame, SyncStatus::accepted())];
    }

    /**
     * @return list<AddressedFrame>
     */
    private function awareness(AddressedFrame $frame): array
    {
        $update = $frame->message->update;

        $this->awareness->apply($update, now: (int) (microtime(true) * 1000));

        foreach ($update->clients() as $client) {
            if (! in_array($client, $this->ownedClients, true)) {
                $this->ownedClients[] = $client;
            }
        }

        // Nothing goes back to the sender. Fanning this out to the document's
        // other connections is the daemon's job, not the session's.
        return [];
    }

    /**
     * @return list<AddressedFrame>
     */
    private function describeAwareness(AddressedFrame $frame): array
    {
        $update = $this->awareness->updateFor();

        return $update->isEmpty() ? [] : [$this->reply($frame, new Awareness($update))];
    }

    private function reply(AddressedFrame $frame, ProviderMessage $message): AddressedFrame
    {
        return new AddressedFrame($frame->documentName, $message);
    }
}
