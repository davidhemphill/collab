<?php

declare(strict_types=1);

namespace Hocuspocus\Server;

/**
 * Decides whether a token may open a document, and with what scope.
 *
 * Deliberately the whole of the authorization surface. Everything
 * application-specific about access — how a token is signed, what a role
 * means, whether a document exists — lives behind this one method, so this
 * package can carry the protocol without carrying anyone's policy.
 */
interface Authenticator
{
    /**
     * @param  string  $documentName  The address the client asked for.
     *
     * @throws AuthenticationFailed When the token is missing, invalid, or
     *                              does not grant access to this document.
     */
    public function authenticate(string $documentName, string $token): Authenticated;
}
