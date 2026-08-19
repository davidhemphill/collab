<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol;

/**
 * The close codes the provider recognizes.
 *
 * The provider treats these differently: some it retries, some it does not.
 * Closing with the wrong one turns a permanent refusal into a client that
 * reconnects forever, which is worse than either outcome on its own.
 */
final class CloseEvent
{
    private function __construct(
        public readonly int $code,
        public readonly string $reason,
        public readonly bool $clientShouldRetry,
    ) {}

    /** A frame larger than the server is willing to read. */
    public static function messageTooBig(): self
    {
        return new self(1009, 'Message Too Big', clientShouldRetry: false);
    }

    /** The client should throw away its document and sync again from scratch. */
    public static function resetConnection(): self
    {
        return new self(4205, 'Reset Connection', clientShouldRetry: true);
    }

    /** No credentials, or credentials we could not verify. */
    public static function unauthorized(): self
    {
        return new self(4401, 'Unauthorized', clientShouldRetry: false);
    }

    /** Credentials understood, access refused. Retrying will not help. */
    public static function forbidden(): self
    {
        return new self(4403, 'Forbidden', clientShouldRetry: false);
    }

    /** The server is going away — a deploy or a drain. Come back shortly. */
    public static function serviceRestart(): self
    {
        return new self(1012, 'Service Restart', clientShouldRetry: true);
    }

    /** A connection that stopped answering pings. Hocuspocus's 4408. */
    public static function connectionTimeout(): self
    {
        return new self(4408, 'Connection Timeout', clientShouldRetry: true);
    }

    /**
     * Something in the host application failed.
     *
     * Not the client's fault and not permanent, so the provider is told to
     * come back: a database that was unreachable for one update is usually
     * reachable for the retry. The connection dies; the process does not.
     */
    public static function internalError(string $reason = 'Internal Error'): self
    {
        return new self(1011, $reason, clientShouldRetry: true);
    }

    /** The client sent something we could not parse. */
    public static function policyViolation(string $reason = 'Policy Violation'): self
    {
        return new self(1008, $reason, clientShouldRetry: false);
    }
}
