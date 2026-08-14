<?php

declare(strict_types=1);

namespace Collab\Protocol\Message;

use Collab\Protocol\MessageType;
use Yjs\Binary\Encoder;
use Yjs\Protocol\Sync\SyncMessage;

/**
 * A y-protocols sync message, addressed to a document.
 *
 * Hocuspocus adds no framing of its own here — the payload is exactly what
 * y-protocols writes, which is why the sync codec lives in yjs-php and this is
 * only a wrapper.
 */
final class Sync implements ProviderMessage
{
    public function __construct(public readonly SyncMessage $message) {}

    public function type(): MessageType
    {
        return MessageType::Sync;
    }

    public function writePayload(Encoder $encoder): void
    {
        $this->message->write($encoder);
    }
}
