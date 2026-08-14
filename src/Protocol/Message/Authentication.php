<?php

declare(strict_types=1);

namespace Hocuspocus\Protocol\Message;

use Hocuspocus\Protocol\AuthMessageType;
use Hocuspocus\Protocol\MessageType;
use Hocuspocus\Protocol\Scope;
use Yjs\Binary\Encoder;

/**
 * The authentication exchange, in all four of its shapes.
 *
 * Three type numbers cover four messages, because `Token` travels both ways: as
 * a bare request from the server and as an answer from the client. What
 * separates them on the wire is only whether a string follows. That works
 * because Hocuspocus sends one message per WebSocket frame, so "nothing
 * follows" is a fact the reader can check rather than a guess.
 */
final class Authentication implements ProviderMessage
{
    private function __construct(
        public readonly AuthMessageType $authType,
        public readonly ?string $token = null,
        public readonly ?string $reason = null,
        public readonly ?Scope $scope = null,
    ) {}

    /**
     * Server to client: "send me your token." Carries no payload.
     */
    public static function tokenRequest(): self
    {
        return new self(AuthMessageType::Token);
    }

    /**
     * Client to server: the token itself.
     */
    public static function token(string $token): self
    {
        return new self(AuthMessageType::Token, token: $token);
    }

    /**
     * Server to client: refused, and why.
     *
     * The provider does not retry after this, which is the point — a client
     * that may never read this document should stop asking.
     */
    public static function permissionDenied(string $reason): self
    {
        return new self(AuthMessageType::PermissionDenied, reason: $reason);
    }

    /**
     * Server to client: accepted, with the scope granted.
     */
    public static function authenticated(Scope $scope): self
    {
        return new self(AuthMessageType::Authenticated, scope: $scope);
    }

    public function isTokenRequest(): bool
    {
        return $this->authType === AuthMessageType::Token && $this->token === null;
    }

    public function type(): MessageType
    {
        return MessageType::Auth;
    }

    public function writePayload(Encoder $encoder): void
    {
        $encoder->writeVarUint($this->authType->value);

        match ($this->authType) {
            AuthMessageType::Token => $this->token === null ? null : $encoder->writeVarString($this->token),
            AuthMessageType::PermissionDenied => $encoder->writeVarString((string) $this->reason),
            AuthMessageType::Authenticated => $encoder->writeVarString(($this->scope ?? Scope::ReadOnly)->value),
        };
    }
}
