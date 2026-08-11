<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

/**
 * "Prévisions" (module spec iteration 7, Espace des chefs, role_min: chief):
 * a read-only projection of the unit's headcount for the target scout year.
 * Never section-scoped, same reasoning as Passage — a chief needs the whole
 * unit's picture to judge whether a section is heading toward being over-
 * or under-staffed. Never writes anything: it has no business in the
 * journal (module spec — "elle ne fait que lire").
 *
 * Anchored on the PUBLIC year + 1, always — the same deliberate exception
 * to Core\ScoutYear\ScoutYearResolver::getEffectiveYear() as
 * Controller\PassageController (see its own docblock): a session preview
 * or an active staff year must never move what "next year" means here.
 */
class ForecastController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ForecastService $forecastService,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private SlotService $slotService
    ) {
    }

    /**
     * GET /previsions
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetLabel = ScoutYearService::nextLabel($publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);
        $targetYear = $this->scoutYearService->findById($targetYearId) ?? $publicYear;

        $forecast = $this->forecastService->getForecast(
            (int) $publicYear['id'],
            (string) $publicYear['label'],
            (int) $targetYear['id'],
            (string) $targetYear['label'],
            $this->slotService->referenceMonthDay()
        );

        return $this->render('@registration/forecast.html.twig', [
            'target_year_label' => $targetYear['label'],
            'current_year_label' => $publicYear['label'],
            'forecast' => $forecast,
        ]);
    }
}
