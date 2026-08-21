<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Yjs\Protocol\Awareness\AwarenessLimits;
use Hemp\Yjs\Protocol\Awareness\AwarenessStore;

/**
 * The presence a document has only while someone has it open.
 *
 * Presence is a property of the document, not of any one connection:
 * everyone editing "4711" must see the same set of cursors, which means
 * every session for "4711" has to share one store. Hocuspocus reaches the
 * same place by giving its Document a single Awareness instance; this is
 * that, without the document.
 *
 * The document itself lives in {@see ResidentStore}, which decorates the
 * host's store with the same residency idea applied to state instead of
 * presence. The two are deliberately separate objects with separate rules: a
 * document whose last connection leaves loses its presence immediately —
 * correctly, since presence is defined as who is here now, and the answer
 * just became nobody — while its state must outlive the room until the last
 * write lands.
 */
final class ResidentDocuments
{
    /** @var array<string, AwarenessStore> */
    private array $awareness = [];

    public function __construct(private readonly AwarenessLimits $limits = new AwarenessLimits) {}

    public function awarenessFor(string $documentName): AwarenessStore
    {
        return $this->awareness[$documentName] ??= new AwarenessStore($this->limits);
    }

    public function unload(string $documentName): void
    {
        unset($this->awareness[$documentName]);
    }

    /**
     * Every loaded document and its awareness, for the expiry sweep.
     *
     * @return iterable<string, AwarenessStore>
     */
    public function each(): iterable
    {
        foreach ($this->awareness as $name => $store) {
            yield (string) $name => $store;
        }
    }
}
