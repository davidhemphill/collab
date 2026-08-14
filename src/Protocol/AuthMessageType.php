<?php

declare(strict_types=1);

namespace Hocuspocus\Protocol;

/**
 * The three authentication shapes, and the one that is really two.
 *
 * `Token` travels in both directions and means different things each way. From
 * the server it is a bare request — "send me your token" — with nothing after
 * it. From the client it is the answer, with the token appended. The type
 * number alone cannot tell them apart; only the presence of a payload can.
 */
enum AuthMessageType: int
{
    case Token = 0;
    case PermissionDenied = 1;
    case Authenticated = 2;
}
