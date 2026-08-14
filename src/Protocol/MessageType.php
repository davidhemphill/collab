<?php

declare(strict_types=1);

namespace Collab\Protocol;

/**
 * The Hocuspocus provider message types.
 *
 * Note the gaps: 4 and 6 are unassigned in the profile this server targets. A
 * reader must reject them rather than assume the numbering is dense, since a
 * later provider version could fill them with something we do not understand.
 */
enum MessageType: int
{
    /** Wraps a y-protocols sync message. */
    case Sync = 0;

    /** Wraps a y-protocols awareness update. */
    case Awareness = 1;

    /** Authentication, in one of four shapes. See {@see AuthMessageType}. */
    case Auth = 2;

    /** "Tell me who else is here." Carries nothing. */
    case QueryAwareness = 3;

    /** An application-defined string, opaque to the protocol. */
    case Stateless = 5;

    /** "I am done with this document." Carries nothing. */
    case Close = 7;

    /** Whether the server accepted the update the client just sent. */
    case SyncStatus = 8;
}
