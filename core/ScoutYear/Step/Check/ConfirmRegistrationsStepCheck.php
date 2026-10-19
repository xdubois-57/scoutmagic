<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « confirm_registrations ». Carries, for now, the separate reminder
 * of the switch veto (« rappel séparé du veto de bascule ») — the
 * requests still open, whichever year they target, block step 14 and
 * never this step. The points to check (pending requests, accepted ones
 * without an intended section) come with the registration module's
 * contract.
 */
final class ConfirmRegistrationsStepCheck implements StepCheckProvider
{
    public function __construct(private ScoutYearAdminService $adminService)
    {
    }

    public function stepKey(): string
    {
        return 'confirm_registrations';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('registrations', 'Aller aux inscriptions', '/config/inscriptions/demandes');

        return new StepCheck(
            primaryAction: $action,
            actions: [$action],
            details: ['blocking_request_count' => $this->adminService->countBlockingRegistrationRequests()]
        );
    }
}
