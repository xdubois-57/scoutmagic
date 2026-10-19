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
use Modules\Registration\Api\ScoutYearPreparationProvider;

/**
 * Step « passage »: how many animés change branch and how many still have
 * no destination section, through the registration module's own contract.
 * A human step — zero passage left undecided is a point less to check,
 * never the step done, and « aucun passage » is said as such.
 *
 * $preparation is null exactly when the module is off, in which case the
 * step is not on the page and this is never called; it still answers
 * nothing rather than fail.
 */
final class PassageStepCheck implements StepCheckProvider
{
    public function __construct(private ?ScoutYearPreparationProvider $preparation)
    {
    }

    public function stepKey(): string
    {
        return 'passage';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('passage', 'Aller aux passages de branche', '/passage');
        if ($this->preparation === null) {
            return new StepCheck(primaryAction: $action, actions: [$action]);
        }

        $total = $this->preparation->countPassages();
        $unassigned = $total > 0 ? $this->preparation->countUnassignedPassages() : 0;

        return new StepCheck(
            pointsToCheck: $unassigned > 0
                ? [$unassigned === 1 ? "1 animé sans section d'arrivée" : "{$unassigned} animés sans section d'arrivée"]
                : [],
            primaryAction: $action,
            actions: [$action],
            details: ['passage_count' => $total, 'unassigned_count' => $unassigned]
        );
    }
}
