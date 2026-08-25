<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Attention;

use Core\Config\AppClock;

/**
 * Collects the unit's current attention points from every contributor.
 *
 * Recomputed on every display, stored nowhere. A point disappears because
 * it stopped being true, never because somebody marked it done — see
 * {@see AttentionPoint}.
 *
 * The one behaviour worth stating out loud is the failure handling: a
 * provider that throws is caught, named on the page as unable to
 * contribute, and the others render normally. A module that is wrong
 * about the unit must not be able to take the page down with it, and a
 * page that hid the failure would be worse than one that reported it —
 * the reader would be looking at a shorter list with no way to know.
 */
class AttentionService
{
    /** @var AttentionPointProvider[] */
    private array $providers;

    /**
     * @param AttentionPointProvider[] $providers core first, then whatever
     *        the composition root wired in for the enabled modules
     *        (ARCHITECTURE.md §7.4 — core never depends on any of them)
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    public function collect(int $scoutYearId): AttentionReport
    {
        $points = [];
        $degraded = [];

        foreach ($this->providers as $provider) {
            try {
                foreach ($provider->collect($scoutYearId) as $point) {
                    $points[] = $point->withSource($provider->sourceLabel());
                }
            } catch (\Throwable) {
                // Deliberately swallowed, and deliberately reported: the
                // exception's message could carry anything, including
                // personal data, so it never reaches the page or the
                // journal from here. The label is enough for a chef
                // d'unité to know which half of the page is missing.
                $degraded[] = $this->safeLabel($provider);
            }
        }

        return new AttentionReport($this->sort($points), array_values(array_unique($degraded)), AppClock::now());
    }

    /**
     * Most pressing first: anything with a deadline, soonest first, then
     * the urgent ones, then the rest. Within a tier, the order the
     * providers gave them, which is each provider's own reasoning.
     *
     * @param AttentionPoint[] $points
     * @return AttentionPoint[]
     */
    private function sort(array $points): array
    {
        $today = AppClock::now();

        usort($points, static function (AttentionPoint $a, AttentionPoint $b) use ($today): int {
            $aDays = $a->daysUntilDue($today);
            $bDays = $b->daysUntilDue($today);

            if ($aDays !== null && $bDays !== null) {
                return $aDays <=> $bDays;
            }
            if ($aDays !== null) {
                return -1;
            }
            if ($bDays !== null) {
                return 1;
            }

            $rank = static fn(AttentionPoint $p): int => $p->severity === AttentionPoint::SEVERITY_URGENT ? 0 : 1;

            return $rank($a) <=> $rank($b);
        });

        return $points;
    }

    /**
     * A provider that throws from `sourceLabel()` too still has to be
     * named — with the one thing that cannot fail.
     */
    private function safeLabel(AttentionPointProvider $provider): string
    {
        try {
            return $provider->sourceLabel();
        } catch (\Throwable) {
            return 'Un module';
        }
    }
}
