<?php

declare(strict_types=1);

namespace Hemp\Collab\Tests\Support;

use Hemp\Collab\Server\DocumentStore;
use Hemp\Yjs\Update\Update;

/**
 * Somewhere for a document to live that is not a database.
 */
class HostStore implements DocumentStore
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
}
