<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « fees » — no check yet. To come: the fees module's accuracy
 * report on the target year's import, never invoices or payments.
 */
final class FeesStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'fees';
    }

    public function check(StepContext $context): StepCheck
    {
        return StepCheck::none();
    }
}
