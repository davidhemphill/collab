<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol\Message;

use Hemp\Collab\Protocol\MessageType;
use Hemp\Yjs\Binary\Encoder;

/**
 * Whether the server accepted the update the client just sent.
 *
 * The flag is a *signed* varInt, not the unsigned one every other count in this
 * protocol uses. The provider reads it with `readVarInt` and compares against
 * 1, so writing it unsigned happens to produce the same bytes for 0 and 1 and
 * would diverge for nothing else — which is exactly the kind of agreement that
 * holds until it doesn't.
 *
 * What a positive status means is narrower than it looks: the update was
 * validated and merged into the server's resident state. It does not promise
 * that the debounced write to storage has happened. That distinction is the
 * durability contract, and it is documented rather than implied.
 */
final class SyncStatus implements ProviderMessage
{
    public function __construct(public readonly bool $applied) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    /**
     * Refused — the sender may not introduce state, or the update was invalid.
     */
    public static function rejected(): self
    {
        return new self(false);
    }

    public function type(): MessageType
    {
        return MessageType::SyncStatus;
    }

    public function writePayload(Encoder $encoder): void
    {
        $encoder->writeVarInt($this->applied ? 1 : 0);
    }
}
