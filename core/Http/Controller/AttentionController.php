<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Attention\AttentionReport;
use Core\Attention\AttentionService;
use Core\Config\AppClock;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\Role;
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
        private ScoutYearResolver $scoutYearResolver,
        private ?Router $router = null
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
        $report = $this->withReachableActions(
            $this->attentionService->collect((int) $currentYear['id']),
            Role::fromString(AuthSession::getRole())
        );

        return $this->render('admin/attention.html.twig', [
            'report' => $report,
            'scout_year' => $currentYear,
            'today' => AppClock::now(),
        ]);
    }

    /**
     * Drops the action of every point whose page the reader cannot open.
     *
     * This page is `admin`, and some points lead further up: every
     * operational alert points at a Maintenance sub-page, which is
     * `superadmin` since issue #619. A chef d'unité still reads the fact —
     * that is why the page shows it to them — but a button answering
     * « accès refusé » would say the opposite of what it offers. The floor
     * is the target route's own, read from the router, the way the
     * breadcrumb decides which ancestors to link (Router::ancestorTrailFor()).
     * A path no GET route declares keeps its link: that is not this
     * method's question.
     */
    private function withReachableActions(AttentionReport $report, Role $viewer): AttentionReport
    {
        if ($this->router === null) {
            return $report;
        }

        $points = [];
        foreach ($report->points as $point) {
            $path = $point->actionUrl !== null ? (string) strtok($point->actionUrl, '?#') : null;
            $floor = $path !== null ? $this->router->roleMinForPath($path) : null;
            $points[] = $floor !== null && !$viewer->hasAccess(Role::fromString($floor))
                ? $point->withoutAction()
                : $point;
        }

        return new AttentionReport($points, $report->degradedSources, $report->computedAt);
    }
}
