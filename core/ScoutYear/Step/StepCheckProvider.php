<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * The data-dependent half of one step of the « Année scoute » workflow —
 * one implementation per step key (Core\ScoutYear\Step\Check\*), wired in
 * the composition root and handed to ScoutYearTransitionService.
 *
 * The engine owns everything else: which steps exist, in which order and
 * phase, of which nature, and whether a step's module is enabled. A
 * provider is only asked about a step that is on the page, so a provider
 * whose module is off is never called — its optional Api\ dependency may
 * be null for that reason alone, and it must cope.
 *
 * A provider reads; it never writes. It is called on every render of the
 * page and again on every mark request (for StepCheck::$markRefusal), so
 * it must be cheap enough for that and must not cache across requests.
 */
interface StepCheckProvider
{
    /** The step_key this provider answers for (ScoutYearTransitionService::STEP_KEYS). */
    public function stepKey(): string;

    public function check(StepContext $context): StepCheck;
}
