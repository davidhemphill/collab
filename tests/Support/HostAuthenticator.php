<?php

declare(strict_types=1);

namespace Hemp\Collab\Tests\Support;

use Hemp\Collab\Protocol\Scope;
use Hemp\Collab\Server\Authenticated;
use Hemp\Collab\Server\Authenticator;

/**
 * A host application's policy, reduced to the smallest thing that is still one.
 *
 * The point of the seam is that this package never learns what a user is, so
 * the double admits everyone and names them — enough to prove the identity and
 * scope it returns survive the trip through the container and out to a socket.
 */
class HostAuthenticator implements Authenticator
{
    public function authenticate(string $documentName, string $token): Authenticated
    {
        return new Authenticated(Scope::ReadWrite, identity: 'host');
    }
}
