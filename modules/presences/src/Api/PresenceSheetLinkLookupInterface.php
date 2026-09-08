<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Api;

use Core\Security\Role;

/**
 * Public contract for consuming modules (ARCHITECTURE.md §7.5): the link
 * to an evening's attendance sheet, for one named reader.
 *
 * The mirror of `Modules\Retro\Api\RetroEventLinkLookupInterface`, and
 * deliberately the same shape — it exists for the same consumer,
 * `Modules\Calendar\Service\PersonalFeedService`, which already knows how
 * to put a per-reader line into an ICS description.
 *
 * **The link is resolved on every read, for the reader.** That feed's own
 * docblock is categorical about why: « un gestionnaire qui a perdu son
 * droit hier doit cesser de voir le détail aujourd'hui, et un jeton qui
 * se souviendrait de ses anciens droits serait une fuite permanente ». So
 * an animateur who leaves the section stops seeing the link at their next
 * agenda refresh, with nothing to revoke.
 *
 * **The short link is not a protection.** `/s/{code}` is public: it
 * abbreviates, it defends nothing. The page behind it is what refuses a
 * visitor who does not staff the section — a check that is needed anyway,
 * since a link can be forwarded. Two independent barriers, neither of
 * which rests on the code staying secret.
 */
interface PresenceSheetLinkLookupInterface
{
    /**
     * The sheet's short link for this reader, or null when the event
     * opens no sheet at all (it is not on a section's calendar) or when
     * this reader does not staff that section.
     *
     * $viewerEmail and $scoutYearId are null when the caller has no
     * identified reader — an anonymous ICS feed, typically — and such a
     * reader never qualifies. The unit feed and a family's feed therefore
     * never carry the link, which is the point: it belongs in an
     * animateur's own feed and nowhere else.
     */
    public function findSheetLink(
        int $eventId,
        Role $viewerRole,
        ?string $viewerEmail,
        ?int $scoutYearId
    ): ?PresenceSheetLink;
}
