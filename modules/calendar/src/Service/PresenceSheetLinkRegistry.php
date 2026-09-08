<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Security\Role;
use Modules\Presences\Api\PresenceSheetLink;
use Modules\Presences\Api\PresenceSheetLinkLookupInterface;

/**
 * Where the presences module's sheet-link lookup plugs in
 * (ARCHITECTURE.md §7.6, the exact shape of RetroEventLinkRegistry beside
 * it): the calendar builds this empty and hands the SAME object to the
 * personal feed; the presences block, further down `public/index.php`,
 * provides its own service into it.
 *
 * **Mutable on purpose, because the two modules consume each other.**
 * `presences` reads `calendar` (which events belong to a section) and
 * `calendar` reads `presences` (the link for this reader) — a plain pair
 * of constructor arguments cannot be ordered in a straight-line script.
 * A holder the feed reads at call time needs neither a rebuild nor a
 * re-registration.
 *
 * It implements the looked-up interface itself, with « nothing provided »
 * behaving exactly as « presences absent »: no link. Callers keep their
 * plain nullable `?PresenceSheetLinkLookupInterface` parameter, so no
 * service and no test learned a new type for this.
 */
class PresenceSheetLinkRegistry implements PresenceSheetLinkLookupInterface
{
    private ?PresenceSheetLinkLookupInterface $lookup = null;

    /**
     * Called once, from the presences module's composition-root block. A
     * second provider would silently shadow the first — refuse loudly
     * instead, same stance as RetroEventLinkRegistry.
     */
    public function provide(PresenceSheetLinkLookupInterface $lookup): void
    {
        if ($this->lookup !== null) {
            throw new \LogicException('A presence sheet-link lookup is already provided.');
        }
        $this->lookup = $lookup;
    }

    public function findSheetLink(
        int $eventId,
        Role $viewerRole,
        ?string $viewerEmail,
        ?int $scoutYearId
    ): ?PresenceSheetLink {
        return $this->lookup?->findSheetLink($eventId, $viewerRole, $viewerEmail, $scoutYearId);
    }
}
