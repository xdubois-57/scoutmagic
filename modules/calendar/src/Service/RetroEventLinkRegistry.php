<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Security\Role;
use Modules\Retro\Api\RetroEventLinkLookupInterface;
use Modules\Retro\Api\RetroLinkSummary;

/**
 * Where the retro module's event-link lookup plugs in (ARCHITECTURE.md
 * §7.6, same shape as VirtualEventRegistry): the calendar builds this
 * empty and hands the SAME object to every service that renders retro
 * links; the retro block, further down `public/index.php`, provides its
 * BoardService into it.
 *
 * **Mutable on purpose, and that is what removed the second calendar
 * block.** `retro` consumes `calendar` (its board picker reads the event
 * lookup) and `calendar` consumes `retro` (this link lookup) — the same
 * cycle rental and calendar have around virtual events. Before this
 * registry the composition root broke it by building the calendar TWICE:
 * once early for everyone else, once after retro with the lookup, the
 * second registration silently dropping whatever the first one carried
 * (the virtual-event registry, concretely). A holder the services read
 * at call time needs neither the rebuild nor the re-registration.
 *
 * It implements the looked-up interface itself, with "nothing provided"
 * behaving exactly as "retro absent": no linked board, no link. Callers
 * keep their `?RetroEventLinkLookupInterface` parameter type — the
 * registry passes through it unchanged, so no service or test learned a
 * new type for this.
 */
class RetroEventLinkRegistry implements RetroEventLinkLookupInterface
{
    private ?RetroEventLinkLookupInterface $lookup = null;

    /**
     * Called once, from the retro module's composition-root block. A
     * second provider would silently shadow the first — refuse loudly
     * instead, same stance as Core\Scheduler\TaskCapabilities.
     */
    public function provide(RetroEventLinkLookupInterface $lookup): void
    {
        if ($this->lookup !== null) {
            throw new \LogicException('A retro event-link lookup is already provided.');
        }
        $this->lookup = $lookup;
    }

    public function findLinkedBoardLink(int $eventId, Role $viewerRole, ?string $viewerEmail, ?int $scoutYearId): ?RetroLinkSummary
    {
        return $this->lookup?->findLinkedBoardLink($eventId, $viewerRole, $viewerEmail, $scoutYearId);
    }

    public function hasLinkedBoard(int $eventId): bool
    {
        return $this->lookup?->hasLinkedBoard($eventId) ?? false;
    }
}
