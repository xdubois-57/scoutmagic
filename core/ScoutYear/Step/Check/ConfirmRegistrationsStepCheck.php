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
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;

/**
 * Step « confirm_registrations », human: the requests for the target year
 * still waiting on the staff — pending ones, and accepted ones without a
 * « section prévue » — are points to check
 * (ScoutYearRegistrationReviewProvider::requestCounts()).
 *
 * Separately, the reminder of the switch veto (« rappel séparé du veto de
 * bascule »): the requests still open, whichever year they target, block
 * step 14 and never this step. That count is the veto's own
 * (ScoutYearAdminService), not derived from the counts above.
 *
 * $details: pending, accepted, accepted_without_section (absent when the
 * module's review contract is not wired), blocking_request_count.
 */
final class ConfirmRegistrationsStepCheck implements StepCheckProvider
{
    public function __construct(
        private ScoutYearAdminService $adminService,
        private ?ScoutYearRegistrationReviewProvider $review
    ) {
    }

    public function stepKey(): string
    {
        return 'confirm_registrations';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('registrations', 'Gérer les inscriptions', '/config/inscriptions/demandes');
        $details = ['blocking_request_count' => $this->adminService->countBlockingRegistrationRequests()];

        $points = [];
        if ($this->review !== null) {
            $counts = $this->review->requestCounts($context->targetId());
            $details += [
                'pending' => $counts->pending,
                'accepted' => $counts->accepted,
                'accepted_without_section' => $counts->acceptedWithoutIntendedSection,
            ];
            if ($counts->pending > 0) {
                $points[] = CheckText::count($counts->pending, 'demande en attente', 'demandes en attente');
            }
            if ($counts->acceptedWithoutIntendedSection > 0) {
                $points[] = CheckText::count(
                    $counts->acceptedWithoutIntendedSection,
                    'demande acceptée sans section prévue',
                    'demandes acceptées sans section prévue'
                );
            }
        }

        return new StepCheck(pointsToCheck: $points, primaryAction: $action, actions: [$action], details: $details);
    }
}
