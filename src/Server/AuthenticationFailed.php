<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use RuntimeException;

/**
 * Access refused.
 *
 * The message is sent to the client verbatim, so it must not distinguish
 * "expired" from "forged" or "no such document" from "not yours" — each of
 * those tells an unauthenticated caller something it has not earned. Put the
 * detail in the log, not in here.
 */
final class AuthenticationFailed extends RuntimeException
{
    public static function because(string $clientSafeReason): self
    {
        return new self($clientSafeReason);
    }

    public static function invalidToken(): self
    {
        return new self('Invalid token.');
    }

    public static function documentMismatch(): self
    {
        return new self('Token does not match document.');
    }
}
