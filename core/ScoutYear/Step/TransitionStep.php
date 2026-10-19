<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * One step of the « Année scoute » workflow as the page renders it —
 * immutable, recomputed on every request by ScoutYearTransitionService.
 *
 * $number is the position among the steps THIS site has (a step of a
 * disabled module is absent and the numbering follows). $state is
 * already final: DONE from the mark (human) or the observation (observed),
 * BLOCKED for an observed step whose action the server refuses, TODO
 * otherwise. Points to check stack on top of either TODO or DONE.
 *
 * Twig reads the booleans through their `is` prefix (`step.done`,
 * `step.blocking`), and the detail of a step through its own template,
 * detailTemplate() — the one file per key that Lot E fills.
 */
final class TransitionStep
{
    /**
     * @param list<string> $pointsToCheck
     * @param list<StepAction> $actions every action of the step, the primary one included
     * @param array<string, mixed> $details data for detailTemplate() only
     */
    public function __construct(
        public readonly string $key,
        public readonly int $number,
        public readonly string $phase,
        public readonly StepNature $nature,
        public readonly StepState $state,
        public readonly string $title,
        public readonly string $description,
        public readonly array $pointsToCheck,
        public readonly ?StepAction $primaryAction,
        public readonly array $actions,
        public readonly ?string $markRefusal,
        public readonly ?string $blockReason,
        public readonly ?string $pendingMessage,
        public readonly ?StepMark $mark,
        public readonly array $details,
        public readonly int $targetYearId
    ) {
    }

    public function isDone(): bool
    {
        return $this->state === StepState::DONE;
    }

    public function isBlocked(): bool
    {
        return $this->state === StepState::BLOCKED;
    }

    public function isHuman(): bool
    {
        return $this->nature === StepNature::HUMAN;
    }

    public function isObserved(): bool
    {
        return $this->nature === StepNature::OBSERVED;
    }

    /**
     * Whether the step counts among the summary's « n blocages »: an
     * observed step the server refuses, or a human step not yet done whose
     * marking the server refuses (step 1 during the campaign).
     */
    public function isBlocking(): bool
    {
        return $this->isBlocked() || ($this->markRefusal !== null && !$this->isDone());
    }

    public function pointsToCheckCount(): int
    {
        return count($this->pointsToCheck);
    }

    /** The step's own detail template, included by the Détails dialog. */
    public function detailTemplate(): string
    {
        return 'admin/scout_year/steps/' . $this->key . '.html.twig';
    }
}
