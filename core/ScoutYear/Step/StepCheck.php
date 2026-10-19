<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * What one StepCheckProvider found for one step: the part of a step that
 * depends on data. The engine (ScoutYearTransitionService) adds the rest —
 * number, phase, nature, title, the human mark — and turns the two into a
 * TransitionStep.
 *
 * Which fields count depends on the step's nature, and the engine ignores
 * the others rather than trusting a check to know:
 * - $observedDone: OBSERVED steps only. A human step is done by its mark,
 *   never by a check (« contrôle automatique qui marquerait une
 *   étape humaine » is ruled out).
 * - $blockReason: OBSERVED steps only — why the server refuses the
 *   step's action right now (the step reads BLOCKED while not done).
 * - $markRefusal: HUMAN steps only — why the server refuses to MARK the
 *   step right now. Checked again on the mark request itself; unmarking
 *   is never refused.
 *
 * $pointsToCheck are short French labels (« 1 animé sans section
 * d'arrivée »). They never block anything. A check without data returns
 * none, and says why in $pendingMessage instead.
 *
 * $actions lists every action of the step, the primary one included;
 * $primaryAction is the single one the page shows outside the Détails
 * dialog. $details is free-form data for the step's own detail template
 * (admin/scout_year/steps/{key}.html.twig), whose keys are a contract
 * between one provider and one template only.
 */
final class StepCheck
{
    /**
     * @param list<string> $pointsToCheck
     * @param list<StepAction> $actions
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly ?bool $observedDone = null,
        public readonly array $pointsToCheck = [],
        public readonly ?StepAction $primaryAction = null,
        public readonly array $actions = [],
        public readonly ?string $blockReason = null,
        public readonly ?string $markRefusal = null,
        public readonly ?string $pendingMessage = null,
        public readonly array $details = []
    ) {
    }

    /** A step the site has nothing to say about yet. */
    public static function none(): self
    {
        return new self();
    }
}
