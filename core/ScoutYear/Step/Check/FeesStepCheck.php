<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Fees\Api\FeeAccuracySummaryProvider;

/**
 * Step « fees », human: the fees module's « Justesse des tarifs » report
 * on the target year's import, as counts (FeeAccuracySummaryProvider) —
 * households to correct and members that cannot be checked for want of an
 * address are points to check, households set aside are information.
 * Never invoices nor payments: that is not this question.
 *
 * The report means nothing before the target year is imported (with no
 * member every count is zero), so the step waits for that import.
 *
 * $summary is null exactly when the module is off, and the step is then
 * not on the page.
 *
 * $details: households_checked, households_to_correct,
 * members_without_address, households_set_aside, imported_at; absent
 * before the import.
 */
final class FeesStepCheck implements StepCheckProvider
{
    public function __construct(private ?FeeAccuracySummaryProvider $summary)
    {
    }

    public function stepKey(): string
    {
        return 'fees';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('fee_accuracy', 'Ouvrir Justesse des tarifs', '/admin/fees/tarifs');
        if ($this->summary === null) {
            return new StepCheck(primaryAction: $action, actions: [$action]);
        }

        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(
                primaryAction: $action,
                actions: [$action],
                pendingMessage: $context->waitingForImportMessage()
            );
        }

        $summary = $this->summary->summaryForYear($context->targetId());

        $points = [];
        if ($summary->householdsToCorrect > 0) {
            $points[] = CheckText::count($summary->householdsToCorrect, 'foyer à corriger', 'foyers à corriger');
        }
        if ($summary->membersWithoutAddress > 0) {
            $points[] = CheckText::count(
                $summary->membersWithoutAddress,
                'membre non vérifiable (sans adresse)',
                'membres non vérifiables (sans adresse)'
            );
        }

        return new StepCheck(
            pointsToCheck: $points,
            primaryAction: $action,
            actions: [$action],
            details: [
                'households_checked' => $summary->householdsChecked,
                'households_to_correct' => $summary->householdsToCorrect,
                'members_without_address' => $summary->membersWithoutAddress,
                'households_set_aside' => $summary->householdsSetAside,
                'imported_at' => $context->latestTargetImport?->importedAt,
            ]
        );
    }
}
