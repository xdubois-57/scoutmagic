<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Calendar\Api\EventDescriptionEnricherInterface;
use Modules\Calendar\Api\VirtualEventViewer;

/**
 * The enrichers of real events' descriptions (§7.6), filled by the
 * modules that plug into Api\EventDescriptionEnricherInterface and read by
 * the personal feed only.
 *
 * Built empty in the calendar's own wiring block and handed to
 * PersonalFeedService; a module registers into it from its own block,
 * later — the same mutable-registry shape as VirtualEventRegistry, which
 * is what lets the calendar read a module it cannot name.
 */
class EventDescriptionEnricherRegistry
{
    /** @var list<EventDescriptionEnricherInterface> */
    private array $enrichers = [];

    public function register(EventDescriptionEnricherInterface $enricher): void
    {
        $this->enrichers[] = $enricher;
    }

    public function hasEnrichers(): bool
    {
        return $this->enrichers !== [];
    }

    /**
     * Every enricher's lines for $eventIds, merged in registration order.
     *
     * An enricher that throws is skipped: an agenda missing one module's
     * lines is far better than a personal feed that fails to generate, and
     * a subscribed client would otherwise keep its stale copy for ever.
     *
     * @param list<int> $eventIds
     * @return array<int, list<string>>
     */
    public function linesFor(array $eventIds, VirtualEventViewer $viewer): array
    {
        if ($eventIds === []) {
            return [];
        }

        $lines = [];
        foreach ($this->enrichers as $enricher) {
            try {
                $contributed = $enricher->describeEvents($eventIds, $viewer);
            } catch (\Throwable) {
                continue;
            }
            foreach ($contributed as $eventId => $eventLines) {
                foreach ($eventLines as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[(int) $eventId][] = $line;
                    }
                }
            }
        }

        return $lines;
    }
}
