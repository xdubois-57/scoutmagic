<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

/**
 * A section's year, assembled: the evenings it held, the animés it holds,
 * and the three figures the page leads with.
 *
 * **A grid of animés × evenings was tried and dropped.** It showed
 * everything and taught nothing, and it ran off any screen at
 * twenty-five animés over thirty meetings. What replaced it is three
 * questions — where is the section, which dates mobilised, who is
 * dropping out — and this object is what answers them.
 */
final class Register
{
    /**
     * @param list<RegisterEvent> $events oldest first
     * @param list<RegisterAnime> $animes least present first
     */
    public function __construct(
        public readonly int $sectionId,
        public readonly string $sectionLabel,
        public readonly ?string $sectionColor,
        public readonly array $events,
        public readonly array $animes,
        public readonly int $animeCount,
        /** Evenings somebody actually pointed — the denominator of every rate here. */
        public readonly int $pointedEventCount,
        public readonly int $averageRate,
        public readonly ?RegisterEvent $best,
        public readonly ?RegisterEvent $worst,
        /**
         * The evening to point next: the first one still to come, and
         * failing that the most recent past one that is still unfinished.
         * Null when there is nothing left to do — which is the ordinary
         * state of a section in July, and draws no shortcut at all.
         */
        public readonly ?RegisterEvent $nextEvent,
        /** How many animés that evening still has nobody's answer for. */
        public readonly int $nextEventPending
    ) {
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * The events in chart order — oldest first, unpointed evenings left
     * out, since a bar at zero for a Saturday nobody pointed would invent
     * a collapse in attendance.
     *
     * @return list<RegisterEvent>
     */
    public function chartEvents(): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(RegisterEvent $event): bool => $event->pointed
        ));
    }

    /**
     * @return list<RegisterAnime>
     */
    public function leastPresent(int $limit): array
    {
        return array_slice($this->animes, 0, max(0, $limit));
    }
}
