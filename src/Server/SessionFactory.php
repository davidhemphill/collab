<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

/**
 * Builds a session for a document.
 *
 * A seam rather than ceremony: the daemon has one authenticator and one store
 * for the whole process, but a session is per document per connection, and the
 * host application may want to vary either by document.
 */
interface SessionFactory
{
    public function __invoke(string $documentName): Session;
}
