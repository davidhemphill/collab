<?php

declare(strict_types=1);

namespace Hocuspocus\Protocol\Message;

use Hocuspocus\Protocol\MessageType;
use Yjs\Binary\Encoder;

/**
 * "Tell me who else is here." Carries nothing but its own type.
 */
final class QueryAwareness implements ProviderMessage
{
    public function type(): MessageType
    {
        return MessageType::QueryAwareness;
    }

    public function writePayload(Encoder $encoder): void
    {
        // Nothing follows the type.
    }
}
