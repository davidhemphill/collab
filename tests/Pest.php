<?php

declare(strict_types=1);

use Hemp\Collab\Protocol\FrameReader;
use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\AuthenticationFailed;
use Hemp\Collab\Server\Authenticator;
use Hemp\Collab\Server\DocumentStore;
use Hemp\Collab\Tests\Support\Transcripts;
use Hemp\Yjs\Update\Update;

/**
 * Assert exact bytes, reporting the difference in hex. A failure that says
 * "0434373131 !== 0434373132" is readable; raw binary in a terminal is not.
 */
expect()->extend('toBeBytes', function (string $expected) {
    expect(bin2hex($this->value))->toBe(bin2hex($expected));

    return $this;
});

/** An authenticator that accepts one token and grants a fixed scope. */
function authenticatorGranting(Scope $scope, string $token = 'good'): Authenticator
{
    return new class($scope, $token) implements Authenticator
    {
        public function __construct(private Scope $scope, private string $token) {}

        public function authenticate(string $documentName, string $token): Authenticated
        {
            if ($token !== $this->token) {
                throw AuthenticationFailed::invalidToken();
            }

            return new Authenticated($this->scope, identity: "user-for-{$documentName}");
        }
    };
}

/** A store that keeps documents in memory. */
function memoryStore(): DocumentStore
{
    return new class implements DocumentStore
    {
        /** @var array<string, Update> */
        public array $documents = [];

        public function load(string $documentName): Update
        {
            return $this->documents[$documentName] ?? Update::empty();
        }

        public function store(string $documentName, Update $update): void
        {
            $this->documents[$documentName] = $update;
        }
    };
}

function seeded(): Update
{
    // A real update, taken from the frame this package records the provider
    // sending. Hand-built bytes would only prove this agrees with itself.
    $frame = (new FrameReader)->read(Transcripts::bytes('sync-update'));

    return $frame->message->message->update();
}
