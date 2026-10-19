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
 * Step « activate_public », observed: done once the public year is the
 * target. Blocked by ScoutYearAdminService::publicActivationBlockers() —
 * staff year not the target, no successful import, registration veto —
 * the very list activatePublicYear() refuses on, so what the page and the
 * review dialog show is what the server enforces.
 *
 * The primary action opens the review before the switch; the POST itself
 * is the review's confirmation, and the server re-checks everything there.
 * $details['blockers'] is the list of ActivationBlocker the review shows
 * with their resolution links, $details['target_label'] the label their
 * messages take.
 */
final class ActivatePublicStepCheck implements StepCheckProvider
{
    public function __construct(private ScoutYearAdminService $adminService)
    {
    }

    public function stepKey(): string
    {
        return 'activate_public';
    }

    public function check(StepContext $context): StepCheck
    {
        if ($context->publicId() === $context->targetId()) {
            return new StepCheck(observedDone: true);
        }

        $target = $context->targetLabel();
        $blockers = $this->adminService->publicActivationBlockers($context->targetId());

        $review = StepAction::openReview('review_switch', 'Revoir avant la bascule…');
        $switch = StepAction::postTo(
            'activate_public',
            "Basculer sur {$target}",
            '/admin/scout-year/activate-public',
            ['scout_year_id' => (string) $context->targetId()],
            "Activer {$target} pour tout le monde ?",
            "Toutes les familles verront {$target} dès la confirmation. Aucun e-mail n'est envoyé.",
            $blockers !== []
        );

        return new StepCheck(
            observedDone: false,
            primaryAction: $review,
            actions: [$review, $switch],
            blockReason: $blockers === [] ? null : $this->refusal($blockers, $target),
            details: ['blockers' => $blockers, 'target_label' => $target]
        );
    }

    /**
     * « La bascule est refusée tant que … » in one sentence (the mock-up of #930).
     *
     * @param non-empty-list<ActivationBlocker> $blockers
     */
    private function refusal(array $blockers, string $target): string
    {
        // Each reason carries its own « que », so « qu'aucun » elides.
        $reasons = array_map(static fn(ActivationBlocker $b): string => match ($b->code) {
            ActivationBlocker::STAFF_YEAR_NOT_TARGET => "que {$target} n'est pas activée pour les staffs",
            ActivationBlocker::NO_SUCCESSFUL_IMPORT => "qu'aucun import réussi de {$target} n'existe",
            default => "que {$b->count} demande(s) d'inscription restent ouvertes",
        }, $blockers);

        return 'La bascule est refusée tant ' . implode(', ', $reasons) . '.';
    }
}
