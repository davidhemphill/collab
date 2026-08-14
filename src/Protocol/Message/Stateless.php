<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol\Message;

use Hemp\Collab\Protocol\MessageType;
use Hemp\Yjs\Binary\Encoder;

/**
 * An application-defined string, opaque to the protocol.
 *
 * Hocuspocus carries this without interpreting it, and so does this server. It
 * is the escape hatch for anything a product needs to say over the same socket
 * that is not a document update.
 */
final class Stateless implements ProviderMessage
{
    public function __construct(public readonly string $payload = '') {}

    public function type(): MessageType
    {
        return MessageType::Stateless;
    }

    public function writePayload(Encoder $encoder): void
    {
        $encoder->writeVarString($this->payload);
    }
}
