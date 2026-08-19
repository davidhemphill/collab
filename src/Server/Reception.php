<?php

declare(strict_types=1);

namespace Hemp\Collab\Server;

use Hemp\Collab\Protocol\AddressedFrame;

/**
 * What handling one frame produced: answers for the sender, and news for the
 * document.
 *
 * The two travel differently, and the session is the only thing that knows
 * which is which — the hub cannot tell an accepted update from a refused one
 * by looking at the frame, and it must not guess, because relaying a refused
 * update would let a read-only client write through a server that declined to
 * store what it sent. Broadcasts go to every connection open on the document,
 * the sender included; Hocuspocus echoes both updates and awareness to their
 * origin, and the echo is load-bearing — it is the only traffic a lone client
 * receives, and the provider closes a socket it hears nothing on.
 */
final class Reception
{
    /**
     * @param  list<AddressedFrame>  $replies  For the connection that spoke.
     * @param  list<AddressedFrame>  $broadcasts  For everyone with the document open.
     */
    private function __construct(
        public readonly array $replies = [],
        public readonly array $broadcasts = [],
    ) {}

    public static function nothing(): self
    {
        return new self;
    }

    public static function replies(AddressedFrame ...$frames): self
    {
        return new self(replies: array_values($frames));
    }

    public static function broadcasts(AddressedFrame ...$frames): self
    {
        return new self(broadcasts: array_values($frames));
    }

    /**
     * @param  list<AddressedFrame>  $replies
     * @param  list<AddressedFrame>  $broadcasts
     */
    public static function of(array $replies, array $broadcasts): self
    {
        return new self(array_values($replies), array_values($broadcasts));
    }
}
