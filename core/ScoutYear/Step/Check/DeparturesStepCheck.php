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
 * Step « departures ». Checks to come with the registration module's
 * campaign contract: the campaign state, the announced departures,
 * and the refusal to mark the step while the campaign is not finished
 * (StepCheck::$markRefusal). Until then the step is marked as before.
 */
final class DeparturesStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'departures';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('departures', "Aller aux départs de l'unité", '/departs');

        return new StepCheck(primaryAction: $action, actions: [$action]);
    }
}
