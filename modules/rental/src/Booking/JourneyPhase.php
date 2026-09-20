<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * One stretch of the journey, with the milestones that fell into it.
 *
 * `BookingPhase` says which stretches exist; this says what this booking's
 * records make of one. Everything on it is computed by `BookingJourney` —
 * nothing recomputes anything, so the summary line a folded phase shows and
 * the boxes an open one shows can never disagree.
 */
final class JourneyPhase
{
    /**
     * @param list<BookingMilestone> $milestones in the order
     *   `BookingMilestones::for()` produced them
     * @param list<BookingStatus> $transitions the status buttons that belong
     *   to this stretch (`BookingPhase::ofTransition()`)
     */
    public function __construct(
        public readonly BookingPhase $phase,
        public readonly array $milestones,
        public readonly array $transitions,
        public readonly bool $isCurrent,
        /** The box this stretch's next piece of work is done in, if any. */
        public readonly ?BookingBox $box = null
    ) {
    }

    public function label(): string
    {
        return $this->phase->label();
    }

    public function description(): string
    {
        return $this->phase->description();
    }

    public function key(): string
    {
        return $this->phase->value;
    }

    /**
     * What a folded phase says about itself, in one line.
     *
     * A count and nothing else — « 3 sur 4 ». The alternative, listing the
     * outstanding labels, is the unfolded phase, and a folded phase that
     * says as much as an open one is a phase that was never folded.
     */
    public function summary(): string
    {
        $applicable = $this->applicable();
        if ($applicable === []) {
            return 'Sans objet';
        }

        $done = count(array_filter($applicable, static fn(BookingMilestone $m): bool => $m->isDone));

        if ($done === count($applicable)) {
            return 'Fait';
        }

        return $done . ' sur ' . count($applicable);
    }

    public function isDone(): bool
    {
        $applicable = $this->applicable();

        return $applicable !== [] && $this->summary() === 'Fait';
    }

    /**
     * The first milestone of this stretch still waiting, or null.
     */
    public function next(): ?BookingMilestone
    {
        foreach ($this->milestones as $milestone) {
            if ($milestone->isApplicable && !$milestone->isDone) {
                return $milestone;
            }
        }

        return null;
    }

    /**
     * @return list<BookingMilestone>
     */
    private function applicable(): array
    {
        return array_values(array_filter(
            $this->milestones,
            static fn(BookingMilestone $m): bool => $m->isApplicable
        ));
    }
}
