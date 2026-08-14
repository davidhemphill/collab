<?php

declare(strict_types=1);

namespace Hocuspocus\Server;

use Hocuspocus\Protocol\Scope;

/**
 * The outcome of a successful authentication.
 *
 * `identity` is whatever the host application wants to carry through the
 * session — a user model, an ID, a claims array. This package never inspects
 * it; it exists so that events, logging, and audit trails downstream have
 * something to name the writer with.
 */
final class Authenticated
{
    public function __construct(
        public readonly Scope $scope,
        public readonly mixed $identity = null,
    ) {}

    public function permitsWriting(): bool
    {
        return $this->scope->permitsWriting();
    }
}
