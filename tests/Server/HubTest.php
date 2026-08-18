<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\AddressedFrame;
use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Message\Authentication;
use Hemp\Collab\Protocol\Message\Awareness;
use Hemp\Collab\Protocol\Message\Close;
use Hemp\Collab\Protocol\Message\Sync;
use Hemp\Collab\Protocol\Message\SyncStatus;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Connection;
use Hemp\Collab\Server\Hub;
use Hemp\Collab\Server\SharedSessionFactory;
use Hemp\Yjs\Id\StateVector;
use Hemp\Yjs\Protocol\Awareness\AwarenessEntry;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;
use Hemp\Yjs\Protocol\Sync\SyncStep1;
use Hemp\Yjs\Protocol\Sync\SyncStep2;

/**
 * Two clients, one document.
 *
 * The session answers whoever spoke; this is the other half — telling everyone
 * else. Without it a server accepts edits correctly and no one ever sees them,
 * which is the failure mode that looks like everything working.
 */
function hub(Scope $scope = Scope::ReadWrite): array
{
    $store = memoryStore();
    $hub = new Hub(new SharedSessionFactory(authenticatorGranting($scope), $store));

    return [$hub, $store];
}

/** A connection that records what it was sent, decoded. */
function client(Hub $hub, string $id): object
{
    $received = new ArrayObject;

    $connection = new Connection(
        id: $id,
        send: function (string $bytes) use ($received): void {
            $received[] = (new FrameReader)->read($bytes);
        },
        disconnect: function () use ($received): void {
            $received['closed'] = true;
        },
        factory: $hub->sessions(),
    );

    $hub->add($connection);

    return new class($connection, $received)
    {
        public function __construct(public Connection $connection, public ArrayObject $received) {}

        /** @return list<AddressedFrame> */
        public function drain(): array
        {
            $frames = array_values(array_filter(
                iterator_to_array($this->received),
                fn ($f) => $f instanceof AddressedFrame,
            ));
            $this->received->exchangeArray([]);

            return $frames;
        }

        public function wasClosed(): bool
        {
            return $this->connection->isClosed();
        }
    };
}

function say(Hub $hub, object $client, $message, string $document = '4711'): void
{
    $hub->receive($client->connection, (new AddressedFrame($document, $message))->encode());
}

function authenticated(Hub $hub, object $client, string $document = '4711'): void
{
    say($hub, $client, Authentication::token('good'), $document);
    $client->drain();
}

it('relays an accepted update to the other client', function () {
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    // Alice hears the acknowledgement; Bob hears the edit.
    expect($alice->drain()[0]->message)->toBeInstanceOf(SyncStatus::class);

    $received = $bob->drain();

    expect($received)->toHaveCount(1)
        ->and($received[0]->message)->toBeInstanceOf(Sync::class)
        ->and($received[0]->message->message->update()->structCount())
        ->toBe(seeded()->structCount());
});

it('does not echo an update back to its sender', function () {
    [$hub] = hub();
    $alice = client($hub, 'a');
    authenticated($hub, $alice);

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    $received = $alice->drain();

    expect($received)->toHaveCount(1)
        ->and($received[0]->message)->toBeInstanceOf(SyncStatus::class);
});

it('never relays an update it refused', function () {
    // The important one. A read-only client that could still broadcast would
    // reach every peer through a server that declined to store what it sent.
    [$hub, $store] = hub(Scope::ReadOnly);
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    expect($alice->drain()[0]->message->applied)->toBeFalse()
        ->and($bob->drain())->toBe([])
        ->and($store->load('4711')->isEmpty())->toBeTrue();
});

it('sends nothing to a connection that has not authenticated', function () {
    // Naming a document used to be enough to join it, so a stranger who knew
    // the name received every edit made to it without presenting a token.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $eve = client($hub, 'e');

    authenticated($hub, $alice);

    // Eve names the document, and nothing else.
    say($hub, $eve, new Sync(new SyncStep1(StateVector::empty())));
    $eve->drain();

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    expect($eve->drain())->toBe([])
        ->and($eve->connection->sessionFor('4711')->isAuthenticated())->toBeFalse()
        ->and($hub->subscriberCount('4711'))->toBe(1);
});

it('does not relay presence from a connection that has not authenticated', function () {
    // Awareness carries no accepted status, so the check that stops a refused
    // update cannot stop this one.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $eve = client($hub, 'e');

    authenticated($hub, $alice);

    say($hub, $eve, new Awareness(new AwarenessUpdate([
        new AwarenessEntry(99, 1, '{"name":"Impostor"}'),
    ])));

    expect($alice->drain())->toBe([]);
});

it('joins the document as soon as the handshake succeeds', function () {
    // The subscriber list is what makes a connection reachable, so a client
    // that authenticates and then says nothing more still receives edits.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    expect($hub->subscriberCount('4711'))->toBe(2);

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    expect($bob->drain()[0]->message)->toBeInstanceOf(Sync::class);
});

it('does not relay a sync step one, which asks rather than tells', function () {
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Sync(new SyncStep1(StateVector::empty())));

    expect($bob->drain())->toBe([]);
});

it('relays presence to the others', function () {
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Awareness(new AwarenessUpdate([
        new AwarenessEntry(7, 1, '{"name":"Ada"}'),
    ])));

    $received = $bob->drain();

    expect($received)->toHaveCount(1)
        ->and($received[0]->message)->toBeInstanceOf(Awareness::class)
        ->and($received[0]->message->update->entries[0]->client)->toBe(7);
});

it('echoes presence back to its sender, which keeps a lone editor connected', function () {
    // @hocuspocus/provider force-closes a socket it has received nothing on for
    // 30 seconds. A single editor in a document sends awareness every 15s and
    // would otherwise hear nothing back between edits, so it drops and
    // reconnects on a loop. The echo is the keepalive.
    [$hub] = hub();
    $alice = client($hub, 'a');

    authenticated($hub, $alice);
    $alice->drain();

    say($hub, $alice, new Awareness(new AwarenessUpdate([
        new AwarenessEntry(7, 1, '{"name":"Ada"}'),
    ])));

    $received = $alice->drain();

    expect($received)->toHaveCount(1)
        ->and($received[0]->message)->toBeInstanceOf(Awareness::class)
        ->and($received[0]->message->update->entries[0]->client)->toBe(7);
});

it('retracts a departed connection\'s presence', function () {
    // A dropped socket produces no message of its own, so the server has to
    // announce the departure on the client's behalf or its cursor never leaves.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Awareness(new AwarenessUpdate([
        new AwarenessEntry(7, 1, '{"name":"Ada"}'),
    ])));
    $bob->drain();

    $hub->remove($alice->connection);

    $received = $bob->drain();

    expect($received)->toHaveCount(1)
        ->and($received[0]->message->update->entries[0]->isRemoval())->toBeTrue()
        ->and($received[0]->message->update->entries[0]->client)->toBe(7);
});

it('only retracts presence the connection introduced', function () {
    // Otherwise one client leaving could evict another from the cursor list.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $alice, new Awareness(new AwarenessUpdate([new AwarenessEntry(7, 1, '{"n":"a"}')])));
    say($hub, $bob, new Awareness(new AwarenessUpdate([new AwarenessEntry(9, 1, '{"n":"b"}')])));
    $alice->drain();
    $bob->drain();

    $hub->remove($alice->connection);

    $retracted = array_map(
        fn ($f) => $f->message->update->entries[0]->client,
        $bob->drain(),
    );

    expect($retracted)->toBe([7]);
});

it('stops relaying to a client that left the document', function () {
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice);
    authenticated($hub, $bob);

    say($hub, $bob, new Close);
    $bob->drain();

    say($hub, $alice, new Sync(SyncStep2::of(seeded())));

    expect($bob->drain())->toBe([])
        ->and($hub->subscriberCount('4711'))->toBe(1);
});

it('keeps documents apart on one connection', function () {
    // A provider multiplexes every open document over one socket, so
    // subscribing to one must not deliver another's traffic.
    [$hub] = hub();
    $alice = client($hub, 'a');
    $bob = client($hub, 'b');

    authenticated($hub, $alice, '4711');
    authenticated($hub, $bob, '9999');

    say($hub, $alice, new Sync(SyncStep2::of(seeded())), '4711');

    expect($bob->drain())->toBe([]);
});

it('closes a connection that sends an undecodable frame', function () {
    // The reader consumes a whole frame or nothing, so a failure means the
    // client's framing is lost and everything after it is guesswork.
    [$hub] = hub();
    $alice = client($hub, 'a');

    $hub->receive($alice->connection, "\xFF\xFF\xFF");

    expect($alice->wasClosed())->toBeTrue()
        ->and($hub->connectionCount())->toBe(0);
});

it('requires each document on a connection to authenticate separately', function () {
    // Authenticating for one document must grant nothing for another.
    [$hub] = hub();
    $alice = client($hub, 'a');

    authenticated($hub, $alice, '4711');

    say($hub, $alice, new Sync(new SyncStep1(StateVector::empty())), '9999');

    expect($alice->drain()[0]->message)->toBeInstanceOf(Authentication::class)
        ->and($alice->connection->sessionFor('9999')->isAuthenticated())->toBeFalse()
        ->and($alice->connection->sessionFor('4711')->isAuthenticated())->toBeTrue();
});
