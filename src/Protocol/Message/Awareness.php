<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol\Message;

use Hemp\Collab\Protocol\MessageType;
use Hemp\Yjs\Binary\Encoder;
use Hemp\Yjs\Protocol\Awareness\AwarenessUpdate;

/**
 * An awareness update, addressed to a document.
 *
 * Unlike a sync message the update is length-prefixed here, so a reader knows
 * where it ends without parsing it.
 */
final class Awareness implements ProviderMessage
{
    public function __construct(public readonly AwarenessUpdate $update) {}

    public function type(): MessageType
    {
        return MessageType::Awareness;
    }

    public function writePayload(Encoder $encoder): void
    {
        $encoder->writeVarBytes($this->update->encode());
    }
}
