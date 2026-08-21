<?php

declare(strict_types=1);

use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Server\ResidentStore;
use Hemp\Yjs\Update\Update;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * The resident layer: a DocumentStore decorating the host's store.
 *
 * Sessions keep calling load() and store() as if nothing changed; these prove
 * what actually happens underneath — one real load per open document, writes
 * on a debounce instead of per keystroke, failures kept dirty and retried,
 * and a flush before anything is dropped. Time is injected, so the whole
 * lifecycle runs without sleeping through it.
 */

/** A backing store that counts its calls and can be told to fail. */
function countingStore(): object
{
    return new class implements DocumentStore
    {
        /** @var array<string, Update> */
        public array $documents = [];

        public int $loads = 0;

        public int $writeAttempts = 0;

        public bool $failing = false;

        public function load(string $documentName): Update
        {
            $this->loads++;

            return $this->documents[$documentName] ?? Update::empty();
        }

        public function store(string $documentName, Update $update): void
        {
            $this->writeAttempts++;

            if ($this->failing) {
                throw new RuntimeException('the database went away');
            }

            $this->documents[$documentName] = $update;
        }
    };
}

/**
 * @return array{0: ResidentStore, 1: object}
 */
function residentLayer(object $store, float $quiet = 2.0, float $maxWait = 10.0, $log = null): array
{
    $clock = new class
    {
        public float $now = 1_000.0;
    };

    $residents = new ResidentStore(
        $store,
        quietSeconds: $quiet,
        maxWaitSeconds: $maxWait,
        log: $log ?? new NullLogger,
        clock: fn (): float => $clock->now,
    );

    return [$residents, $clock];
}

it('loads the document once and answers every later load from memory', function () {
    $store = countingStore();
    $store->documents['4711'] = seeded();
    [$residents] = residentLayer($store);

    $first = $residents->load('4711');
    $second = $residents->load('4711');

    expect($store->loads)->toBe(1)
        ->and($first)->toBe($second)
        ->and($first->structCount())->toBe(seeded()->structCount());
});

it('accepts a store without touching the database', function () {
    $store = countingStore();
    [$residents] = residentLayer($store);

    $residents->store('4711', seeded());

    expect($store->writeAttempts)->toBe(0)
        ->and($residents->load('4711')->structCount())->toBe(seeded()->structCount())
        ->and($residents->dirtyCount())->toBe(1);
});

it('writes once the typing has been quiet long enough', function () {
    $store = countingStore();
    [$residents, $clock] = residentLayer($store, quiet: 2.0);

    $residents->store('4711', seeded());

    $clock->now += 1.0;
    $residents->flush();

    expect($store->writeAttempts)->toBe(0);

    $clock->now += 1.5;
    $residents->flush();

    expect($store->writeAttempts)->toBe(1)
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount())
        ->and($residents->dirtyCount())->toBe(0);
});

it('does not write the same state twice', function () {
    $store = countingStore();
    [$residents, $clock] = residentLayer($store);

    $residents->store('4711', seeded());
    $clock->now += 3.0;
    $residents->flush();
    $residents->flush();

    $clock->now += 60.0;
    $residents->flush();

    expect($store->writeAttempts)->toBe(1);
});

it('writes anyway when the typing never pauses', function () {
    // The quiet timer restarts with every accepted change, so a person who
    // never stops typing would otherwise never be saved. The max-wait clock
    // runs from the first unsaved change and does not restart.
    $store = countingStore();
    [$residents, $clock] = residentLayer($store, quiet: 2.0, maxWait: 10.0);

    $residents->store('4711', seeded());

    for ($second = 1; $second <= 9; $second++) {
        $clock->now += 1.0;
        $residents->store('4711', seeded());
        $residents->flush();

        expect($store->writeAttempts)->toBe(0);
    }

    $clock->now += 1.0;
    $residents->store('4711', seeded());
    $residents->flush();

    expect($store->writeAttempts)->toBe(1);
});

it('keeps a document dirty when the write fails, and retries with backoff', function () {
    $log = new class extends AbstractLogger
    {
        public array $lines = [];

        public function log($level, $message, array $context = []): void
        {
            $this->lines[] = [$level, $context];
        }
    };

    $store = countingStore();
    $store->failing = true;
    [$residents, $clock] = residentLayer($store, log: $log);

    $residents->store('4711', seeded());
    $clock->now += 3.0;
    $residents->flush();

    // Failed, logged, still dirty — and not hammered: the very next sweep is
    // inside the backoff window and must not try again.
    expect($store->writeAttempts)->toBe(1)
        ->and($residents->dirtyCount())->toBe(1)
        ->and($log->lines)->toHaveCount(1)
        ->and($log->lines[0][0])->toBe('error')
        ->and($log->lines[0][1]['document'])->toBe('4711');

    $residents->flush();

    expect($store->writeAttempts)->toBe(1);

    // The database comes back; the backoff runs out; nothing was lost.
    $store->failing = false;
    $clock->now += 1.0;
    $residents->flush();

    expect($store->writeAttempts)->toBe(2)
        ->and($residents->dirtyCount())->toBe(0)
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());
});

it('flushes and drops the document when the last person leaves', function () {
    $store = countingStore();
    [$residents] = residentLayer($store);

    $residents->store('4711', seeded());
    $residents->unload('4711');

    expect($store->writeAttempts)->toBe(1)
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());

    // Dropped: the next open loads from the backing store again.
    $residents->load('4711');

    expect($store->loads)->toBe(1);
});

it('drops a clean document on unload without writing anything', function () {
    $store = countingStore();
    $store->documents['4711'] = seeded();
    [$residents] = residentLayer($store);

    $residents->load('4711');
    $residents->unload('4711');

    expect($store->writeAttempts)->toBe(0);

    $residents->load('4711');

    expect($store->loads)->toBe(2);
});

it('never evicts a dirty document whose flush failed', function () {
    $store = countingStore();
    $store->failing = true;
    [$residents, $clock] = residentLayer($store);

    $residents->store('4711', seeded());
    $residents->unload('4711');

    expect($store->writeAttempts)->toBe(1)
        ->and($residents->dirtyCount())->toBe(1);

    // The sweep keeps retrying after the room has emptied, and the document
    // is only dropped once the write finally lands.
    $store->failing = false;
    $clock->now += 2.0;
    $residents->flush();

    expect($store->writeAttempts)->toBe(2)
        ->and($residents->dirtyCount())->toBe(0)
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());

    $residents->load('4711');

    expect($store->loads)->toBe(1);
});

it('serves the resident state to someone who reopens before a failed flush lands', function () {
    // The resident copy is newer than anything the database holds, so a
    // reopen must cancel the pending drop and must not reload stale bytes.
    $store = countingStore();
    $store->failing = true;
    [$residents, $clock] = residentLayer($store);

    $residents->store('4711', seeded());
    $residents->unload('4711');

    $reopened = $residents->load('4711');

    expect($store->loads)->toBe(0)
        ->and($reopened->structCount())->toBe(seeded()->structCount());

    // And because the room is occupied again, the eventual write keeps the
    // document resident rather than dropping it.
    $store->failing = false;
    $clock->now += 60.0;
    $residents->flush();

    $residents->load('4711');

    expect($store->loads)->toBe(0)
        ->and($residents->dirtyCount())->toBe(0);
});

it('drains everything dirty immediately for shutdown', function () {
    $store = countingStore();
    [$residents] = residentLayer($store);

    $residents->store('4711', seeded());
    $residents->store('4712', seeded());

    // No time has passed at all — a shutdown does not wait for debounces.
    expect($residents->drain())->toBe(0)
        ->and($store->writeAttempts)->toBe(2)
        ->and($residents->dirtyCount())->toBe(0);
});

it('reports the documents a drain could not save', function () {
    $store = countingStore();
    $store->failing = true;
    [$residents] = residentLayer($store);

    $residents->store('4711', seeded());

    expect($residents->drain())->toBe(1);

    $store->failing = false;

    expect($residents->drain())->toBe(0)
        ->and($store->documents['4711']->structCount())->toBe(seeded()->structCount());
});
