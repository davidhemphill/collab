<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\QueryAwareness;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\Session;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Update\Update;

/**
 * The session state machine, driven with no framework underneath it.
 *
 * That it can be tested this way is the point of the seam: everything
 * application-specific reaches the session through two interfaces, so the
 * protocol logic is exercised here and the host application's policy is
 * exercised where that policy lives.
 */
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

    it('asks the client for state of its own after answering', function () {
        // The half that is easy to omit: without a step one of our own, a
        // browser holding work the server lost keeps holding it, because
        // nothing ever asks. Hocuspocus answers a step one the same way.
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies)->toHaveCount(2)
            ->and($replies[1]->message->message)->toBeInstanceOf(SyncStep1::class)
            ->and($replies[1]->message->message->stateVector->encode())
            ->toBeBytes(seeded()->stateVector()->encode());
    });

    it('asks for state even when it holds none', function () {
        // A brand new document is exactly when the client is most likely to be
        // the only place the work exists.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($replies[1]->message->message)->toBeInstanceOf(SyncStep1::class);
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

    it('does not write an update that changes nothing', function () {
        // Every client answers the server's step one with a step two, and most
        // carry nothing new. Writing those would be a database round trip per
        // connection, and would take reading down with the write path.
        $writes = 0;
        $store = new class($writes) implements DocumentStore
        {
            public function __construct(public int &$writes) {}

            public function load(string $documentName): Update
            {
                return seeded();
            }

            public function store(string $documentName, Update $update): void
            {
                $this->writes++;
            }
        };

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $replies = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($replies[0]->message->applied)->toBeTrue()
            ->and($writes)->toBe(0);
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
