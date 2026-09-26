<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

/**
 * What one check of a connection found.
 */
enum CheckOutcome
{
    /** Meta answered and accepts the authorisation. */
    case Ok;

    /** Meta refused the authorisation, or the account no longer qualifies. */
    case Refused;

    /** The token's end has passed; it was not sent. */
    case Expired;

    /** No usable answer — the network, or Meta itself. The last verdict stands. */
    case Unreachable;

    /** Nothing to check. */
    case NotConnected;
}
