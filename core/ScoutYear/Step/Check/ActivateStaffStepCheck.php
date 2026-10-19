<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\ScoutYear\ActivationBlocker;
use Core\ScoutYear\ScoutYearAdminService;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « activate_staff », observed: done while the staff year is the
 * target. Blocked — by ScoutYearAdminService::staffActivationBlockers(),
 * the very list activateStaffYear() refuses on — while the target has no
 * successful import (hard block 2); the primary action is then the
 * import itself. Open registration requests are only a reminder here.
 */
final class ActivateStaffStepCheck implements StepCheckProvider
{
    public function __construct(private ScoutYearAdminService $adminService)
    {
    }

    public function stepKey(): string
    {
        return 'activate_staff';
    }

    public function check(StepContext $context): StepCheck
    {
        $target = $context->targetLabel();
        $public = $context->publicLabel();
        $details = ['blocking_request_count' => $this->adminService->countBlockingRegistrationRequests()];

        if ($context->staffYearId === $context->targetId()) {
            $deactivate = StepAction::postTo(
                'deactivate_staff',
                'Désactiver pour les staffs',
                '/admin/scout-year/deactivate-staff',
                [],
                "Désactiver {$target} pour les staffs ?",
                "Les staffs reviennent sur {$public}. Aucun e-mail n'est envoyé."
            );

            return new StepCheck(observedDone: true, primaryAction: $deactivate, actions: [$deactivate], details: $details);
        }

        $blockers = $this->adminService->staffActivationBlockers($context->targetId());
        $activate = StepAction::postTo(
            'activate_staff',
            'Activer pour les staffs',
            '/admin/scout-year/activate-staff',
            ['scout_year_id' => (string) $context->targetId()],
            "Activer {$target} pour les staffs ?",
            "Les animateurs et intendants de {$target} verront l'année préparée. Les familles restent sur "
                . "{$public}. Aucun e-mail n'est envoyé.",
            $blockers !== []
        );

        if ($blockers === []) {
            return new StepCheck(observedDone: false, primaryAction: $activate, actions: [$activate], details: $details);
        }

        $import = StepAction::linkTo('import', "Importer {$target}", $context->importUrl());
        $reasons = implode(' ', array_map(static fn(ActivationBlocker $b): string => $b->message($target), $blockers));

        return new StepCheck(
            observedDone: false,
            primaryAction: $import,
            actions: [$import, $activate],
            blockReason: $reasons . " L'activation pour les staffs est refusée tant que l'import n'a pas eu lieu.",
            details: $details + ['blockers' => $blockers]
        );
    }
}
