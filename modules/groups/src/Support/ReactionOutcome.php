<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

/**
 * What a reaction toggle actually did.
 *
 * Service\ReactionService used to answer this with a plain bool meaning
 * "was the key valid", which was enough while nothing depended on the
 * difference between putting a reaction on and taking it back off.
 * Notifications do (prompt 10): removing a reaction must not tell the
 * author somebody reacted. A third bool parameter would have made the two
 * meanings indistinguishable at the call site — hence a named outcome.
 */
enum ReactionOutcome
{
    /** Not one of the fixed six keys — nothing was written (→ 400). */
    case INVALID;

    case ADDED;

    case REMOVED;
}
