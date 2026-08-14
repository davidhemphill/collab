<?php

declare(strict_types=1);

namespace Collab\Protocol\Message;

use Collab\Protocol\AddressedFrame;
use Collab\Protocol\MessageType;
use Yjs\Binary\Encoder;

/**
 * The payload of an addressed frame, after the document name and type byte.
 *
 * Every message writes only its own payload. The address and the type are the
 * frame's business, not the message's — see {@see AddressedFrame}.
 */
interface ProviderMessage
{
    public function type(): MessageType;

    public function writePayload(Encoder $encoder): void;
}
