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
 * Step « trombinoscope ». Individual photos per section to come:
 * this year's, inherited from an earlier year, none.
 */
final class TrombinoscopeStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'trombinoscope';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('trombinoscope', 'Aller aux animateurs', '/trombinoscope');

        return new StepCheck(primaryAction: $action, actions: [$action]);
    }
}
