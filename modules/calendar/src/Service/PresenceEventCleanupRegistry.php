<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Presences\Api\PresenceEventCleanupInterface;

/**
 * Where the presences module's event cleanup plugs in (ARCHITECTURE.md
 * §7.6, the exact shape of PresenceSheetLinkRegistry beside it): the
 * calendar builds this empty and hands the SAME object to
 * CalendarEventService, and the presences block, further down
 * `public/index.php`, provides its own service into it.
 *
 * Mutable for the same reason as its neighbour — the two modules consume
 * each other, and a straight-line composition root cannot order a plain
 * pair of constructor arguments.
 *
 * It implements the interface itself, with « nothing provided » behaving
 * exactly as « presences absent »: nothing to forget.
 */
class PresenceEventCleanupRegistry implements PresenceEventCleanupInterface
{
    private ?PresenceEventCleanupInterface $cleanup = null;

    /**
     * Called once, from the presences module's composition-root block. A
     * second provider would silently shadow the first — refuse loudly.
     */
    public function provide(PresenceEventCleanupInterface $cleanup): void
    {
        if ($this->cleanup !== null) {
            throw new \LogicException('A presence event cleanup is already provided.');
        }
        $this->cleanup = $cleanup;
    }

    public function forgetEvent(int $eventId): void
    {
        $this->cleanup?->forgetEvent($eventId);
    }
}
