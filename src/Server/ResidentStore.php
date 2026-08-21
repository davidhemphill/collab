<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Closure;
use Hemp\Yjs\Update\Update;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * A {@see DocumentStore} that keeps the binder on the desk.
 *
 * Decorates the host's store. The sessions keep calling load() and store()
 * exactly as before — this layer answers load() from memory after the first
 * call and turns store() into "mark dirty", leaving the real write to
 * {@see flush()}, which the daemon drives on a short cadence the way it
 * already drives awareness expiry. Hocuspocus debounces with the same two
 * timers and the same defaults.
 *
 * The acknowledgement contract follows: a positive SyncStatus means the
 * change is merged into the resident state, not that it is on disk. The
 * clients hold the document too, and a reconnecting client hands back
 * anything a crashed server never wrote — the handshake asks.
 *
 * A backing store that throws costs a retry, not the connection: the
 * resident stays dirty and the next flush tries again, backing off. A
 * document whose last connection leaves is flushed and dropped, so memory is
 * bounded by open documents — but a dirty document is never dropped.
 */
final class ResidentStore implements DocumentStore
{
    /** @var array<string, ResidentDocument> */
    private array $documents = [];

    /**
     * @param  ?Closure(): float  $clock  Seconds, as microtime(true) gives them.
     *                                    Injectable so the debounce lifecycle is
     *                                    testable without sleeping through it.
     */
    public function __construct(
        private readonly DocumentStore $store,
        private readonly float $quietSeconds = 2.0,
        private readonly float $maxWaitSeconds = 10.0,
        private readonly LoggerInterface $log = new NullLogger,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * The document, from memory after the first call.
     *
     * The first person to open a document triggers the one real load;
     * everyone after them — and every later message from anyone — is answered
     * from the resident state. The daemon is single-threaded, so "the same
     * instant" is still one at a time and the single-flight rule costs
     * nothing to keep.
     */
    public function load(string $documentName): Update
    {
        $document = $this->documents[$documentName]
            ??= new ResidentDocument($this->store->load($documentName));

        // Someone is here, so a drop pending from an earlier failed flush is
        // off — and they are served the resident state, which is newer than
        // anything the database holds.
        $document->retain();

        return $document->state();
    }

    /**
     * An accepted change: new truth in memory, a write owed to the backing
     * store. The write itself happens in {@see flush()}, on the debounce.
     */
    public function store(string $documentName, Update $update): void
    {
        $document = $this->documents[$documentName] ??= new ResidentDocument($update);

        $document->replace($update, $this->now());
        $document->retain();
    }

    /**
     * Write every dirty document whose debounce has run out.
     *
     * The daemon calls this on a short cadence, the way it calls
     * {@see Hub::expireAwareness()}. A failed write is logged, kept dirty,
     * and retried with backoff — never surfaced to a connection, because by
     * the time it happens the change was acknowledged long ago.
     */
    public function flush(): void
    {
        $now = $this->now();

        foreach ($this->documents as $name => $document) {
            if ($document->isDue($now, $this->quietSeconds, $this->maxWaitSeconds) && $document->mayAttempt($now)) {
                $this->write((string) $name, $document, $now);
            }
        }
    }

    /**
     * Write everything dirty right now, timers and backoff be damned — the
     * daemon is shutting down and there is no later. Returns how many
     * documents could not be written, so the caller can retry or at least
     * refuse to pretend the exit was clean.
     */
    public function drain(): int
    {
        $now = $this->now();
        $stillDirty = 0;

        foreach ($this->documents as $name => $document) {
            if ($document->isDirty() && ! $this->write((string) $name, $document, $now)) {
                $stillDirty++;
            }
        }

        return $stillDirty;
    }

    public function dirtyCount(): int
    {
        return count(array_filter($this->documents, fn (ResidentDocument $document) => $document->isDirty()));
    }

    /**
     * The last person left. The document is flushed on the spot if it is
     * dirty and dropped once it is clean; a document whose flush failed stays
     * resident, stays dirty, and is retried by {@see flush()} until the write
     * lands or someone reopens it.
     */
    public function unload(string $documentName): void
    {
        $document = $this->documents[$documentName] ?? null;

        if ($document === null) {
            return;
        }

        $document->release();

        if ($document->isDirty()) {
            $this->write($documentName, $document, $this->now());
        } else {
            unset($this->documents[$documentName]);
        }
    }

    private function write(string $documentName, ResidentDocument $document, float $now): bool
    {
        try {
            $this->store->store($documentName, $document->state());
        } catch (Throwable $failure) {
            $document->failed($now);

            $this->log->error('Deferred store failed for {document}, keeping it dirty: {message}', [
                'document' => $documentName,
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);

            return false;
        }

        $document->flushed();

        if ($document->isReleased()) {
            unset($this->documents[$documentName]);
        }

        return true;
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }
}
