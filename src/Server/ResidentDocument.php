<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Yjs\Update\Update;

/**
 * One open document's truth, and the record of what the database owes it.
 *
 * While anyone has the document open, this object's state — not the stored
 * blob — is what the server merges into and answers sync questions from. The
 * blob catches up on a debounce, so between an accepted change and the next
 * write the two disagree, and everything here exists to manage that gap:
 * when the write is due, whether the last one failed, and whether the room
 * has emptied so the document can be dropped once it is clean.
 *
 * Time arrives as an argument everywhere. This class never looks at a clock,
 * which is what lets the whole lifecycle — quiet timer, max wait, backoff —
 * be tested without sleeping through it.
 */
final class ResidentDocument
{
    private bool $dirty = false;

    /** When the most recent accepted change was merged. */
    private float $lastChangedAt = 0.0;

    /** When the state first ran ahead of the stored blob. */
    private float $firstDirtyAt = 0.0;

    private int $failedWrites = 0;

    /** No write attempt before this moment; moves back on each failure. */
    private float $nextAttemptAt = 0.0;

    /** The room is empty; drop this document as soon as it is clean. */
    private bool $released = false;

    public function __construct(private Update $state) {}

    public function state(): Update
    {
        return $this->state;
    }

    /**
     * An accepted change: the merged update becomes the truth, the write clock
     * restarts, and — if this is the first unsaved change — the max-wait clock
     * starts. The max-wait clock deliberately does not restart: it exists for
     * the person who never pauses, whose quiet timer would otherwise never fire.
     */
    public function replace(Update $state, float $now): void
    {
        $this->state = $state;

        if (! $this->dirty) {
            $this->dirty = true;
            $this->firstDirtyAt = $now;
        }

        $this->lastChangedAt = $now;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Someone is here — cancel a pending drop. A person reopening a document
     * whose last flush failed must be served the resident state, which is newer
     * than anything the database holds.
     */
    public function retain(): void
    {
        $this->released = false;
    }

    /**
     * The last person left. The document may be dropped once its state is
     * safely stored — and not a moment before, because dropping a dirty
     * document is losing acknowledged work.
     */
    public function release(): void
    {
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Whether the debounce says it is time to write: the typing has paused,
     * or it never pauses and the max wait is up, or the room has emptied and
     * there is nothing to wait for.
     */
    public function isDue(float $now, float $quietSeconds, float $maxWaitSeconds): bool
    {
        return $this->dirty && (
            $this->released
            || $now - $this->lastChangedAt >= $quietSeconds
            || $now - $this->firstDirtyAt >= $maxWaitSeconds
        );
    }

    /**
     * Whether enough time has passed since the last failed write. Always true
     * while nothing has failed.
     */
    public function mayAttempt(float $now): bool
    {
        return $now >= $this->nextAttemptAt;
    }

    /**
     * The write landed. The state stays — cleanliness is not emptiness —
     * but nothing is owed to the database until the next accepted change.
     */
    public function flushed(): void
    {
        $this->dirty = false;
        $this->failedWrites = 0;
        $this->nextAttemptAt = 0.0;
    }

    /**
     * The write did not land. The state stays dirty — a database that blinked
     * must never cost acknowledged work — and the next attempt backs off
     * exponentially, capped so an outage of hours still retries every half
     * minute rather than never.
     */
    public function failed(float $now): void
    {
        $this->failedWrites++;
        $this->nextAttemptAt = $now + min(30.0, 2 ** min($this->failedWrites - 1, 5));
    }
}
