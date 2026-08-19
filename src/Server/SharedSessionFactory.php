<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Yjs\Protocol\Awareness\AwarenessStore;

/**
 * The ordinary case: every document uses the same authenticator and store.
 */
final class SharedSessionFactory implements SessionFactory
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly DocumentStore $documents,
    ) {}

    public function __invoke(string $documentName, AwarenessStore $awareness): Session
    {
        return new Session($this->authenticator, $this->documents, $awareness);
    }
}
