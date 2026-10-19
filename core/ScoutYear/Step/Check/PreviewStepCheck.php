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
 * Step « preview », human: whether the caller's own session is previewing
 * the target year is said for information, and that is all — previewing
 * is not reviewing, so it marks nothing (« La prévisualisation
 * ne décide jamais d'un accès ni d'une étape »).
 */
final class PreviewStepCheck implements StepCheckProvider
{
    public function stepKey(): string
    {
        return 'preview';
    }

    public function check(StepContext $context): StepCheck
    {
        $target = $context->targetLabel();
        $active = $context->sessionPreviewId === $context->targetId();

        $action = $active
            ? StepAction::postTo(
                'clear_preview',
                'Quitter la prévisualisation',
                '/admin/scout-year/clear-preview',
                [],
                'Quitter la prévisualisation ?',
                "Le site vous montrera de nouveau l'année qui vous revient. Aucun e-mail n'est envoyé."
            )
            : StepAction::postTo(
                'preview',
                "Prévisualiser {$target}",
                '/admin/scout-year/preview',
                ['scout_year_id' => (string) $context->targetId()],
                "Prévisualiser {$target} ?",
                "Le site vous montrera {$target}, à vous seul et pour cette session. Personne d'autre n'est "
                    . "concerné. Aucun e-mail n'est envoyé."
            );

        return new StepCheck(primaryAction: $action, actions: [$action], details: ['preview_active' => $active]);
    }
}
