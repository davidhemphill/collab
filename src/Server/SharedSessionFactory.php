<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Yjs\Protocol\Awareness\AwarenessLimits;

/**
 * The ordinary case: every document uses the same authenticator and store.
 */
final class SharedSessionFactory implements SessionFactory
{
    public function __construct(
        private readonly Authenticator $authenticator,
        private readonly DocumentStore $documents,
        private readonly AwarenessLimits $awarenessLimits = new AwarenessLimits,
    ) {}

    public function __invoke(string $documentName): Session
    {
        return new Session($this->authenticator, $this->documents, $this->awarenessLimits);
    }
}
