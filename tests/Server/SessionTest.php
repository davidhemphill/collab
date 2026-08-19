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
use Hemp\Collab\Server\Reception;
use Hemp\Collab\Server\Session;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessStore;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;
use Hemp\Yjs\Protocol\Sync\SyncUpdate;
use Hemp\Yjs\Update\Update;

/**
 * The session state machine, driven with no framework underneath it.
 *
 * That it can be tested this way is the point of the seam: everything
 * application-specific reaches the session through two interfaces, so the
 * protocol logic is exercised here and the host application's policy is
 * exercised where that policy lives.
 */
function open(Session $session, string $document = '4711', string $token = 'good'): Reception
{
    return $session->receive(new AddressedFrame($document, Authentication::token($token)));
}

describe('handshake', function () {
    it('grants the scope the authenticator returns', function () {
        $session = new Session(authenticatorGranting(Scope::ReadOnly), memoryStore());

        $reception = open($session);

        expect($reception->replies[0]->message->scope)->toBe(Scope::ReadOnly)
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

        $reception = open($session, token: 'wrong');

        expect($reception->replies[0]->message->authType->name)->toBe('PermissionDenied')
            ->and($reception->replies[0]->message->reason)->toBe('Invalid token.')
            ->and($session->isAuthenticated())->toBeFalse();
    });

    it('asks for a token before answering anything else', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies[0]->message->isTokenRequest())->toBeTrue();
    });

    it('ignores a token request sent the wrong way', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $reception = $session->receive(new AddressedFrame('4711', Authentication::tokenRequest()));

        expect($reception->replies[0]->message->authType->name)->toBe('PermissionDenied')
            ->and($session->isAuthenticated())->toBeFalse();
    });
});

describe('syncing', function () {
    it('answers a sync step one from the store', function () {
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies[1]->message->message)->toBeInstanceOf(SyncStep2::class)
            ->and($reception->replies[1]->message->message->update()->structCount())
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

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies)->toHaveCount(2)
            ->and($reception->replies[0]->message->message)->toBeInstanceOf(SyncStep1::class)
            ->and($reception->replies[0]->message->message->stateVector->encode())
            ->toBeBytes(seeded()->stateVector()->encode())
            ->and($reception->replies[1]->message->message)->toBeInstanceOf(SyncStep2::class);
    });

    it('asks for state even when it holds none', function () {
        // A brand new document is exactly when the client is most likely to be
        // the only place the work exists.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies[0]->message->message)->toBeInstanceOf(SyncStep1::class);
    });

    it('merges a writer update into the store', function () {
        $store = memoryStore();
        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($reception->replies[0]->message)->toBeInstanceOf(SyncStatus::class)
            ->and($reception->replies[0]->message->applied)->toBeTrue()
            ->and($store->load('4711')->contains(seeded()))->toBeTrue()
            // The change travels to the document as an Update — never as the
            // step two it may have arrived as, which would tell every peer
            // that its own question had been answered.
            ->and($reception->broadcasts)->toHaveCount(1)
            ->and($reception->broadcasts[0]->message->message)->toBeInstanceOf(SyncUpdate::class);
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

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($reception->replies[0]->message->applied)->toBeTrue()
            ->and($writes)->toBe(0)
            // And nothing is said to the room: Yjs emits no update event when
            // a document did not change, so Hocuspocus broadcasts nothing.
            ->and($reception->broadcasts)->toBe([]);
    });

    it('refuses an update from a read-only session and stores nothing', function () {
        $store = memoryStore();
        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($reception->replies[0]->message->applied)->toBeFalse()
            ->and($store->load('4711')->isEmpty())->toBeTrue()
            ->and($reception->broadcasts)->toBe([]);
    });

    it('acknowledges a read-only session echoing back state', function () {
        // A read-only client still completes the handshake, so it answers our
        // step one with a step two. Refusing that would break the exchange it
        // is entitled to.
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(SyncStep2::of(seeded()))),
        );

        expect($reception->replies[0]->message->applied)->toBeTrue();
    });

    it('lets a read-only session read', function () {
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadOnly), $store);
        open($session);

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies[1]->message->message->update()->structCount())->toBeGreaterThan(0);
    });

    it('reads the document it was opened for, not the one a frame names', function () {
        // The address on an authenticated frame is not re-checked per message;
        // the session is bound to the document it authenticated against.
        $store = memoryStore();
        $store->store('4711', seeded());

        $session = new Session(authenticatorGranting(Scope::ReadWrite), $store);
        open($session, document: '4711');

        $reception = $session->receive(
            new AddressedFrame('4711', new Sync(new SyncStep1(StateVector::empty()))),
        );

        expect($reception->replies[1]->message->message->update()->structCount())->toBeGreaterThan(0);
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

    it('does not come to own a client whose state the store rejected', function () {
        // Every provider restates every state it receives, so a peer's
        // presence arrives on this connection too — at a clock the shared
        // store has already seen. Owning it would mean this connection's
        // disconnect erased another person's cursor.
        $shared = new AwarenessStore;

        $first = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        $second = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        open($first);
        open($second);

        $ada = new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]);
        $first->receive(new AddressedFrame('4711', new Awareness($ada)));
        $second->receive(new AddressedFrame('4711', new Awareness($ada)));

        expect($first->ownedClients())->toBe([7])
            ->and($second->ownedClients())->toBe([]);
    });

    it('broadcasts an accepted state to the document, sender included', function () {
        // Hocuspocus fans awareness out to every connection with no origin
        // exclusion, and the echo is the heartbeat that keeps a lone client's
        // socket alive. The broadcast is re-encoded from the store, so what
        // the room hears is what the server accepted.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $reception = $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        expect($reception->replies)->toBe([])
            ->and($reception->broadcasts)->toHaveCount(1)
            ->and($reception->broadcasts[0]->message->update->entries[0]->client)->toBe(7)
            ->and($reception->broadcasts[0]->message->update->entries[0]->state)->toBe('{"name":"Ada"}');
    });

    it('broadcasts a clock renewal even though nothing changed', function () {
        // The renewal is the heartbeat: a client re-announces itself every
        // fifteen seconds, and rebroadcasting it is what stops every peer's
        // thirty-second expiry timer from firing on an idle cursor.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        $reception = $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 2, '{"name":"Ada"}')]),
        )));

        expect($reception->broadcasts)->toHaveCount(1);
    });

    it('says nothing when the store accepted nothing', function () {
        // A stale clock changes nothing, so nothing reaches the room.
        // y-protocols emits no event here and Hocuspocus is silent; without
        // this, each client's restatement of what it just heard would
        // circulate through the room forever.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 2, '{"name":"Ada"}')]),
        )));

        $reception = $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 2, '{"name":"Ada"}')]),
        )));

        expect($reception->broadcasts)->toBe([])
            ->and($reception->replies)->toBe([]);
    });

    it('tells a newcomer who is already here', function () {
        // Hocuspocus sends the document's presence the moment a connection is
        // established. Without it, a person opening a busy document sees an
        // empty room until each peer happens to renew.
        $shared = new AwarenessStore;

        $first = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        open($first);
        $first->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        $second = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        $reception = open($second);

        expect($reception->replies)->toHaveCount(2)
            ->and($reception->replies[1]->message)->toBeInstanceOf(Awareness::class)
            ->and($reception->replies[1]->message->update->entries[0]->client)->toBe(7);
    });

    it('tells a newcomer nothing when the room is empty', function () {
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());

        $reception = open($session);

        expect($reception->replies)->toHaveCount(1);
    });

    it('answers a query with everyone present, not only its own clients', function () {
        // Hocuspocus answers QueryAwareness with the document's entire
        // awareness state — presence belongs to the document.
        $shared = new AwarenessStore;

        $first = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        $second = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        open($first);
        open($second);

        $first->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 1, '{"name":"Ada"}')]),
        )));

        $reception = $second->receive(new AddressedFrame('4711', new QueryAwareness));

        expect($reception->replies[0]->message)->toBeInstanceOf(Awareness::class)
            ->and($reception->replies[0]->message->update->entries[0]->client)->toBe(7);
    });

    it('answers a query even when nobody is present', function () {
        // Hocuspocus replies with the awareness state it has, empty included.
        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore());
        open($session);

        $reception = $session->receive(new AddressedFrame('4711', new QueryAwareness));

        expect($reception->replies)->toHaveCount(1)
            ->and($reception->replies[0]->message->update->isEmpty())->toBeTrue();
    });

    it('keeps the departed client\'s clock when retracting, so a stale message cannot reinstate it', function () {
        $shared = new AwarenessStore;

        $session = new Session(authenticatorGranting(Scope::ReadWrite), memoryStore(), $shared);
        open($session);
        $session->receive(new AddressedFrame('4711', new Awareness(
            new AwarenessUpdate([new AwarenessEntry(7, 3, '{"name":"Ada"}')]),
        )));

        $removal = $session->retract([7]);

        expect($removal->entries[0]->clock)->toBe(4)
            ->and($shared->knows(7))->toBeFalse()
            // The clock survives the departure: any message Ada sent before
            // leaving loses to it, so her cursor cannot flicker back.
            ->and($shared->clockFor(7))->toBe(4)
            ->and($session->ownedClients())->toBe([]);
    });
});
