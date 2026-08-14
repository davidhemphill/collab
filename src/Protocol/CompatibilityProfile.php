<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol;

/**
 * The exact client software this server is built to interoperate with.
 *
 * A profile is a promise about observable behavior, and the point of naming it
 * in code rather than only in prose is that a version bump becomes a decision
 * somebody makes instead of a dependency that drifted.
 *
 * Profile 2 will add provider v4, which changes enough to need its own entry:
 * an optional version field in authentication, session-aware addresses of the
 * form `documentName + NUL + sessionId`, and bare one-byte application ping
 * frames. None of that is handled here, and a v4 client is expected to fail
 * visibly rather than half-work.
 */
final class CompatibilityProfile
{
    private function __construct(
        public readonly int $number,
        public readonly string $provider,
        public readonly string $yjs,
        public readonly string $yProtocols,
        public readonly string $lib0,
    ) {}

    /**
     * The profile Livemark runs today.
     */
    public static function one(): self
    {
        return new self(
            number: 1,
            provider: '3.4.4',
            yjs: '13.6.29',
            yProtocols: '1.0.7',
            lib0: '0.2.117',
        );
    }

    /**
     * The message types this profile defines.
     *
     * 4 and 6 are unassigned. A reader must reject them rather than treat the
     * numbering as dense, because a later provider could fill them in.
     *
     * @return list<MessageType>
     */
    public function messageTypes(): array
    {
        return MessageType::cases();
    }

    public function describe(): string
    {
        return sprintf(
            'Profile %d: @hocuspocus/provider %s, yjs %s, y-protocols %s, lib0 %s',
            $this->number,
            $this->provider,
            $this->yjs,
            $this->yProtocols,
            $this->lib0,
        );
    }
}
