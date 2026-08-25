<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Attention\AttentionService;
use Core\Config\AppClock;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Twig\Environment;

/**
 * The attention-points page — the unit's current state, recalculated at
 * every consultation.
 *
 * It is permanent and depends on no recent import: it is as meaningful in
 * June as the day after an import. That is precisely why it is a separate
 * page from an import's report, which is dated and frozen.
 */
class AttentionController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private AttentionService $attentionService,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * GET /admin/points-attention
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $currentYear = $this->scoutYearResolver->getCurrentPublicYear();
        $report = $this->attentionService->collect((int) $currentYear['id']);

        return $this->render('admin/attention.html.twig', [
            'report' => $report,
            'scout_year' => $currentYear,
            'today' => AppClock::now(),
        ]);
    }
}
