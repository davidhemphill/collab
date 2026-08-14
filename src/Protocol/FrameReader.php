<?php

declare(strict_types=1);

namespace Hocuspocus\Protocol;

use Hocuspocus\Protocol\Message\Authentication;
use Hocuspocus\Protocol\Message\Awareness;
use Hocuspocus\Protocol\Message\Close;
use Hocuspocus\Protocol\Message\ProviderMessage;
use Hocuspocus\Protocol\Message\QueryAwareness;
use Hocuspocus\Protocol\Message\Stateless;
use Hocuspocus\Protocol\Message\Sync;
use Hocuspocus\Protocol\Message\SyncStatus;
use Yjs\Binary\DecodeLimits;
use Yjs\Binary\Decoder;
use Yjs\Exception\DecodeException;
use Yjs\Exception\MalformedInput;
use Yjs\Protocol\Awareness\AwarenessLimits;
use Yjs\Protocol\Awareness\AwarenessUpdate;
use Yjs\Protocol\Sync\SyncMessageReader;

/**
 * Reads an addressed frame off the wire.
 *
 * Every frame arriving here came from a socket that may not have been
 * authenticated yet, so this is the server's outermost untrusted surface. It
 * inherits yjs-php's bounded decoder, which means a hostile length costs a
 * comparison rather than an allocation, and every failure is a typed
 * {@see DecodeException} the connection handler can catch in one place.
 */
final class FrameReader
{
    public function __construct(
        private readonly DecodeLimits $limits = new DecodeLimits,
        private readonly AwarenessLimits $awarenessLimits = new AwarenessLimits,
    ) {}

    /**
     * @throws DecodeException
     */
    public function read(string $bytes): AddressedFrame
    {
        $decoder = new Decoder($bytes, $this->limits);

        $documentName = $decoder->readVarString();

        $position = $decoder->position();
        $type = MessageType::tryFrom($decoder->readVarUint());

        if ($type === null) {
            throw new MalformedInput(sprintf(
                'Unknown provider message type at offset %d in a frame for %s.',
                $position,
                $documentName,
            ));
        }

        $message = $this->readMessage($type, $decoder);

        // One message per frame is what the provider sends. Anything left over
        // means we misread the payload, and continuing would resynchronize on
        // whatever happened to follow.
        $decoder->assertAtEnd();

        return new AddressedFrame($documentName, $message);
    }

    /**
     * @throws DecodeException
     */
    private function readMessage(MessageType $type, Decoder $decoder): ProviderMessage
    {
        return match ($type) {
            MessageType::Sync => new Sync(SyncMessageReader::read($decoder)),
            MessageType::Awareness => new Awareness(
                AwarenessUpdate::decode($decoder->readVarBytes(), $this->awarenessLimits),
            ),
            MessageType::Auth => $this->readAuthentication($decoder),
            MessageType::QueryAwareness => new QueryAwareness,
            MessageType::Stateless => new Stateless($decoder->readVarString()),
            MessageType::Close => new Close,
            MessageType::SyncStatus => new SyncStatus($decoder->readVarInt() === 1),
        };
    }

    /**
     * The one message whose meaning depends on what does *not* follow it.
     *
     * A `Token` with a payload is a client answering; a `Token` with nothing
     * after it is the server asking. Distinguishing them by remaining bytes is
     * sound only because a frame carries exactly one message.
     *
     * @throws DecodeException
     */
    private function readAuthentication(Decoder $decoder): Authentication
    {
        $position = $decoder->position();
        $authType = AuthMessageType::tryFrom($decoder->readVarUint());

        if ($authType === null) {
            throw new MalformedInput(sprintf('Unknown authentication type at offset %d.', $position));
        }

        return match ($authType) {
            AuthMessageType::Token => $decoder->hasMore()
                ? Authentication::token($decoder->readVarString())
                : Authentication::tokenRequest(),
            AuthMessageType::PermissionDenied => Authentication::permissionDenied($decoder->readVarString()),
            AuthMessageType::Authenticated => $this->readAuthenticated($decoder),
        };
    }

    /**
     * @throws DecodeException
     */
    private function readAuthenticated(Decoder $decoder): Authentication
    {
        $position = $decoder->position();
        $raw = $decoder->readVarString();
        $scope = Scope::tryFrom($raw);

        if ($scope === null) {
            throw new MalformedInput(sprintf(
                'Unknown authorization scope "%s" at offset %d.',
                $raw,
                $position,
            ));
        }

        return Authentication::authenticated($scope);
    }
}
