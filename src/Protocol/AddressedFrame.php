<?php

declare(strict_types=1);

namespace Collab\Protocol;

use Collab\Protocol\Message\ProviderMessage;
use Yjs\Binary\Encoder;

/**
 * One WebSocket frame: which document it concerns, and what it says.
 *
 * A provider multiplexes every document it has open over a single socket, so
 * the address is not decoration — it is the only thing that says which resident
 * document a message belongs to. Nothing below this layer knows about
 * documents; nothing above it should have to re-derive the address.
 *
 * ```text
 *   varString  document name
 *   varUint    message type
 *   ...        payload, per type
 * ```
 */
final class AddressedFrame
{
    public function __construct(
        public readonly string $documentName,
        public readonly ProviderMessage $message,
    ) {}

    public function type(): MessageType
    {
        return $this->message->type();
    }

    public function write(Encoder $encoder): Encoder
    {
        $encoder->writeVarString($this->documentName)
            ->writeVarUint($this->message->type()->value);

        $this->message->writePayload($encoder);

        return $encoder;
    }

    public function encode(): string
    {
        return $this->write(new Encoder)->toBytes();
    }
}
