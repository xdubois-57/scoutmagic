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
 * Step « desk_members » — no check yet. To come: the gaps between what
 * was prepared and what was imported.
 */
final class DeskMembersStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'desk_members';
    }

    public function check(StepContext $context): StepCheck
    {
        return StepCheck::none();
    }
}
