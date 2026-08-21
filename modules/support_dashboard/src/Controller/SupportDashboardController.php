<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Twig\Environment;

/**
 * `/support-dashboard` (`role_min: superadmin`) — the receiver's view of
 * every installation reporting to it (ARCHITECTURE.md §8.44).
 *
 * View state lives entirely in the query string: no cookie, no local
 * storage, no session. Arriving with no query string always yields the same
 * default view — active installations only, newest report first.
 */
class SupportDashboardController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SupportDashboardService $dashboardService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $filters = SupportDashboardFilters::fromQuery($request->getQueryAll());

        return $this->render('@support_dashboard/index.html.twig', [
            'filters' => $filters,
            'view' => $this->dashboardService->buildView($filters),
        ]);
    }

    /**
     * `GET /support-dashboard/installations/{id}` — the detail dialog's
     * body, rendered server-side (so Twig escapes every value) and fetched
     * on click rather than embedded once per row: a page of installations
     * would otherwise carry a full JSON payload each.
     *
     * @param array<string, string> $params
     */
    public function detail(Request $request, array $params): Response
    {
        $installation = $this->dashboardService->findDetail((int) ($params['id'] ?? 0));
        if ($installation === null) {
            return new Response('', 404);
        }

        return $this->render('@support_dashboard/partials/detail.html.twig', [
            'installation' => $installation,
        ]);
    }
}
