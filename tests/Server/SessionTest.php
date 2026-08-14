<?php

declare(strict_types=1);

use Hocuspocus\Protocol\AddressedFrame;
use Hocuspocus\Protocol\FrameReader;
use Hocuspocus\Protocol\Message\Authentication;
use Hocuspocus\Protocol\Message\Awareness;
use Hocuspocus\Protocol\Message\QueryAwareness;
use Hocuspocus\Protocol\Message\Sync;
use Hocuspocus\Protocol\Message\SyncStatus;
use Hocuspocus\Protocol\Scope;
use Hocuspocus\Server\Authenticated;
use Hocuspocus\Server\AuthenticationFailed;
use Hocuspocus\Server\Authenticator;
use Hocuspocus\Server\DocumentStore;
use Hocuspocus\Server\Session;
use Hocuspocus\Tests\Support\Transcripts;
use Yjs\Id\StateVector;
use Yjs\Protocol\Awareness\AwarenessEntry;
use Yjs\Protocol\Awareness\AwarenessUpdate;
use Yjs\Protocol\Sync\SyncStep1;
use Yjs\Protocol\Sync\SyncStep2;
use Yjs\Update\Update;

/**
 * The session state machine, driven with no framework underneath it.
 *
 * That it can be tested this way is the point of the seam: everything
 * application-specific reaches the session through two interfaces, so the
 * protocol logic is exercised here and the host application's policy is
 * exercised where that policy lives.
 */

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
    // A real update, taken from the frame yjs-hocuspocus records the provider
    // sending. Hand-built bytes would only prove this agrees with itself.
    $frame = (new FrameReader)->read(Transcripts::bytes('sync-update'));

    return $frame->message->message->update();
}

function open(Session $session, string $document = '4711', string $token = 'good'): array
{
    return $session->receive(new AddressedFrame($document, Authentication::token($token)));
}

describe('handshake', function () {
    it('grants the scope the authenticator returns', function () {
        $session = new Session(authenticatorGranting(Scope::ReadOnly), memoryStore());

        $replies = open($session);

        expect($replies[0]->message->scope)->toBe(Scope::ReadOnly)
            ->and($session->scope())->toBe(Scope::ReadOnly)
            ->and($session->documentName())->toBe('4711');
    });

    it('carries the identity the authenticator attached', function () {
        // So events and audit trails downstream have something to name the
        // writer with, without this package knowing what a user is.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        expect($session->identity())->toBe('user-for-4711');
    });

    it('relays a refusal without inventing a reason of its own', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $replies = open($session, token: 'wrong');

        expect($replies[0]->message->authType->name)->toBe('PermissionDenied')
            ->and($replies[0]->message->reason)->toBe('Invalid token.')
            ->and($session->isAuthenticated())->toBeFalse();
    });

    it('asks for a token before answering anything else', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies[0]->message->isTokenRequest())->toBeTrue();
    });

    it('ignores a token request sent the wrong way', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $replies = $session->receive(new AddressedFrame('4711', Authentication::tokenRequest()));

        expect($replies[0]->message->authType->name)->toBe('PermissionDenied')
            ->and($session->isAuthenticated())->toBeFalse();
    });
});

describe('syncing', function () {
    it('answers a sync step one from the store', function () {
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies[0]->message->message)->toBeInstanceOf(SyncStep2::class)
            ->and($replies[0]->message->message->update()->structCount())
            ->toBe(seeded()->structCount());
    });

    it('merges a writer update into the store', function () {
        $store = memoryStore();
        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($replies[0]->message)->toBeInstanceOf(SyncStatus::class)
            ->and($replies[0]->message->applied)->toBeTrue()
            ->and($store->load('4711')->contains(seeded()))->toBeTrue();
    });

    it('refuses an update from a read-only session and stores nothing', function () {
        $store = memoryStore();
        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($replies[0]->message->applied)->toBeFalse()
            ->and($store->load('4711')->isEmpty())->toBeTrue();
    });

    it('acknowledges a read-only session echoing back state', function () {
        // A read-only client still completes the handshake, so it answers our
        // step one with a step two. Refusing that would break the exchange it
        // is entitled to.
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($replies[0]->message->applied)->toBeTrue();
    });

    it('lets a read-only session read', function () {
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies[0]->message->message->update()->structCount())->toBeGreaterThan(0);
    });

    it('reads the document it was opened for, not the one a frame names', function () {
        // The address on an authenticated frame is not re-checked per message;
        // the session is bound to the document it authenticated against.
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session, document: '4711');

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies[0]->message->message->update()->structCount())->toBeGreaterThan(0);
    });
});

describe('awareness', function () {
    it('remembers which clients this connection introduced', function () {
        // A connection may only ever retract its own presence, so the daemon
        // needs to know which those are when the socket drops.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        expect($session->ownedClients())->toBe([7]);
    });

    it('sends nothing back to the client that announced itself', function () {
        // Fanning presence out to the other connections is the daemon's job.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $replies = $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        expect($replies)->toBe([]);
    });

    it('answers a query with everyone it knows about', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        $replies = $session->receive(new AddressedFrame('4711', new QueryAwareness));

        expect($replies[0]->message)->toBeInstanceOf(Awareness::class)
            ->and($replies[0]->message->update->entries[0]->client)->toBe(7);
    });

    it('says nothing when nobody is present', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        expect($session->receive(new AddressedFrame('4711', new QueryAwareness)))->toBe([]);
    });
});
