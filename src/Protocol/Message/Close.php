<?php

declare(strict_types=1);

namespace Hemp\Collab\Protocol\Message;

use Hemp\Collab\Protocol\MessageType;
use Hemp\Yjs\Binary\Encoder;

/**
 * "I am done with this document."
 *
 * A provider multiplexes several documents over one socket, so leaving a
 * document is not the same as closing the connection. This message says the
 * former; the WebSocket close frame says the latter.
 */
final class Close implements ProviderMessage
{
    public function type(): MessageType
    {
        return MessageType::Close;
    }

    public function writePayload(Encoder $encoder): void
    {
        // Nothing follows the type.
    }
}
