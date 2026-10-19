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

/**
 * Step « badges ». Informative view to come: badges per section,
 * default badges given nowhere, cross-section badges present only in some.
 */
final class BadgesStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'badges';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('badges', 'Aller aux staffs et badges', '/chefs/staffs');

        return new StepCheck(primaryAction: $action, actions: [$action]);
    }
}
