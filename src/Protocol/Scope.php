<?php

declare(strict_types=1);

namespace Hocuspocus\Protocol;

/**
 * What an authenticated session is permitted to do.
 *
 * These strings are on the wire — the provider compares them literally — so
 * they are not ours to tidy up.
 */
enum Scope: string
{
    case ReadWrite = 'read-write';

    case ReadOnly = 'readonly';

    public function permitsWriting(): bool
    {
        return $this === self::ReadWrite;
    }
}
