<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * The fifteen milestones, staged (§6.15).
 *
 * Pure, and derived from a derivation: it takes what
 * `BookingMilestones::for()` already computed and adds no fact of its own.
 * That is the whole point — the checklist and the journey cannot drift
 * apart, because there is only one of them.
 *
 * Two questions the page asks, and this answers once for both:
 *
 * - **« L'action suivante »** — the first applicable milestone that is not
 *   done, and nothing else. One card, one thing to do. When every
 *   applicable milestone is done there is none, and the card says so rather
 *   than inventing work.
 * - **« Où en est cette location »** — the five stretches, the one holding
 *   that milestone unfolded and the rest reduced to a count. A phase with
 *   nothing applicable in it (no contract on this asset, the stay module
 *   off) still appears, greyed: a stretch that disappears reads as a
 *   stretch somebody skipped.
 */
final class BookingJourney
{
    /**
     * @param list<JourneyPhase> $phases
     */
    private function __construct(
        private readonly array $phases,
        private readonly ?BookingMilestone $next,
        /** @var list<BookingStatus> */
        private readonly array $lifted
    ) {
    }

    /**
     * @param list<BookingMilestone> $milestones from `BookingMilestones::for()`
     * @param list<BookingStatus> $transitions from `BookingTransition::allowedFrom()`
     */
    public static function of(array $milestones, array $transitions = []): self
    {
        /** @var array<string, list<BookingMilestone>> $grouped */
        $grouped = [];
        foreach (BookingPhase::cases() as $phase) {
            $grouped[$phase->value] = [];
        }

        foreach ($milestones as $milestone) {
            // An unshelved key lands in the last stretch rather than
            // nowhere: it renders at the end of the journey, which is odd,
            // where dropping it would be a line of the checklist silently
            // missing. `BookingJourneyTest` fails before that ships.
            $phase = BookingPhase::of($milestone->key) ?? BookingPhase::AFTER_STAY;
            $grouped[$phase->value][] = $milestone;
        }

        $next = null;
        foreach ($milestones as $milestone) {
            if ($milestone->isApplicable && !$milestone->isDone) {
                $next = $milestone;
                break;
            }
        }

        // The buttons « L'action suivante » shows itself, and therefore the
        // ones the stretches must NOT show again: one page offering the
        // same « Confirmée » twice is a page where pressing either is a
        // guess about which one counts.
        $lifted = self::liftedFor($next, $transitions);

        /** @var array<string, list<BookingStatus>> $buttons */
        $buttons = [];
        foreach ($transitions as $target) {
            if (in_array($target, $lifted, true)) {
                continue;
            }
            $buttons[BookingPhase::ofTransition($target)->value][] = $target;
        }

        // The stretch holding that milestone is the one that unfolds. With
        // nothing left to do — a closed file, a refused one — it is the
        // last stretch that has anything applicable at all, because that is
        // where the file actually ended; unfolding none would leave the
        // reader of a finished booking with five closed lines and no way to
        // tell which of them was the last to happen.
        $current = $next !== null
            ? (BookingPhase::of($next->key) ?? BookingPhase::AFTER_STAY)
            : self::lastStretchWithWork($grouped);

        $phases = [];
        foreach (BookingPhase::cases() as $phase) {
            $lines = $grouped[$phase->value];
            $phases[] = new JourneyPhase(
                $phase,
                $lines,
                $buttons[$phase->value] ?? [],
                $phase === $current,
                self::boxOf($lines)
            );
        }

        return new self($phases, $next, $lifted);
    }

    /**
     * Which status buttons « L'action suivante » renders in full, rather
     * than sending the reader to the stretch that holds them.
     *
     * Two milestones have a status transition for a button, and only two.
     * « Décision prise sur la demande » is answered by every transition
     * `BookingTransition` still offers — confirming, refusing, proposing,
     * asking for more — which is why the whole set comes up rather than a
     * chosen one: the card must not decide for the manager which decision
     * they were about to take. « Location clôturée » is answered by exactly
     * one, and cancelling, which is offered beside it, is not a way of
     * finishing a stay.
     *
     * Everything else is settled in a box, and the card links there.
     *
     * @param list<BookingStatus> $transitions
     * @return list<BookingStatus>
     */
    private static function liftedFor(?BookingMilestone $next, array $transitions): array
    {
        if ($next === null) {
            return [];
        }

        if ($next->key === 'decision') {
            return $transitions;
        }

        if ($next->key === 'closed') {
            return array_values(array_filter(
                $transitions,
                static fn(BookingStatus $s): bool => $s === BookingStatus::CLOSED
            ));
        }

        return [];
    }

    /**
     * @return list<BookingStatus>
     */
    public function liftedTransitions(): array
    {
        return $this->lifted;
    }

    /**
     * @return list<JourneyPhase>
     */
    public function phases(): array
    {
        return $this->phases;
    }

    /**
     * The one thing « L'action suivante » shows, or null when there is none.
     */
    public function next(): ?BookingMilestone
    {
        return $this->next;
    }

    /**
     * Where that one thing is done, or null when its button is on the
     * journey itself (holding the dates, confirming, closing).
     */
    public function nextBox(): ?BookingBox
    {
        return $this->next === null ? null : BookingBox::forMilestone($this->next->key);
    }

    public function isComplete(): bool
    {
        return $this->next === null;
    }

    /**
     * The box a stretch sends the reader to: the one where its first
     * outstanding milestone is settled, falling back to the one where its
     * last applicable milestone was — a finished stretch still wants to
     * show where its evidence is filed.
     *
     * @param list<BookingMilestone> $milestones
     */
    private static function boxOf(array $milestones): ?BookingBox
    {
        $fallback = null;

        foreach ($milestones as $milestone) {
            if (!$milestone->isApplicable) {
                continue;
            }

            $box = BookingBox::forMilestone($milestone->key);
            if ($box === null) {
                continue;
            }

            if (!$milestone->isDone) {
                return $box;
            }

            $fallback = $box;
        }

        return $fallback;
    }

    /**
     * @param array<string, list<BookingMilestone>> $grouped
     */
    private static function lastStretchWithWork(array $grouped): ?BookingPhase
    {
        $last = null;

        foreach (BookingPhase::cases() as $phase) {
            foreach ($grouped[$phase->value] as $milestone) {
                if ($milestone->isApplicable) {
                    $last = $phase;
                    break;
                }
            }
        }

        return $last;
    }
}
