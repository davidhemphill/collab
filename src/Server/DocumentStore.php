<?php

declare(strict_types=1);

namespace Collab\Server;

use Yjs\Update\Update;

/**
 * Where a document's collaborative state lives.
 *
 * Keyed by the document name off the wire rather than by any model, so this
 * package never learns what a document is in the host application. An
 * implementation is free to be Eloquent, a file, or memory.
 *
 * Implementations must treat a document they have never seen as empty rather
 * than an error: a document that exists but has never been opened is the
 * normal first case, not a failure.
 */
interface DocumentStore
{
    public function load(string $documentName): Update;

    public function store(string $documentName, Update $update): void;
}
