<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * The whole « Année scoute » workflow for one transition, as
 * ScoutYearTransitionService::buildWorkflow() returns it, with the
 * figures of the page's summary computed from the steps rather than
 * stored next to them.
 */
final class TransitionWorkflow
{
    /**
     * @param list<TransitionPhase> $phases
     * @param list<TransitionStep> $steps the same steps, in page order
     */
    public function __construct(
        public readonly array $phases,
        public readonly array $steps,
        public readonly string $deskEncodingDate,
        public readonly ?PublicYearSwitch $lastPublicSwitch,
        /**
         * The two stored Desk dates did not hold together and both
         * defaults apply (DeskImportYearDates::fromSettings()) — said on
         * the page, as on the import page, never applied in silence.
         */
        public readonly bool $deskDatesFellBack = false
    ) {
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function doneCount(): int
    {
        return count(array_filter($this->steps, static fn(TransitionStep $s): bool => $s->isDone()));
    }

    public function pointsToCheckCount(): int
    {
        return array_sum(array_map(static fn(TransitionStep $s): int => $s->pointsToCheckCount(), $this->steps));
    }

    public function blockingCount(): int
    {
        return count(array_filter($this->steps, static fn(TransitionStep $s): bool => $s->isBlocking()));
    }

    /**
     * The step the page highlights: the first one not yet done. 0 when
     * everything is done — the workflow is over and nothing is current.
     */
    public function currentStepNumber(): int
    {
        foreach ($this->steps as $step) {
            if (!$step->isDone()) {
                return $step->number;
            }
        }

        return 0;
    }

    public function step(string $key): ?TransitionStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }
}
