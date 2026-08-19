<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Yjs\Protocol\Awareness\AwarenessStore;

/**
 * Builds a session for a document.
 *
 * A seam rather than ceremony: the daemon has one authenticator and one store
 * for the whole process, but a session is per document per connection, and the
 * host application may want to vary either by document.
 *
 * The awareness store arrives from outside because it is not the factory's to
 * choose: presence is shared by every session on the same document, and the
 * hub owns that sharing. A factory that built its own store would give each
 * connection a private room.
 */
interface SessionFactory
{
    public function __invoke(string $documentName, AwarenessStore $awareness): Session;
}
