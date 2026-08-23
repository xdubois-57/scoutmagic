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
use Core\Journal\JournalService;
use Core\Security\CsrfGuard;
use Modules\SupportDashboard\Service\SupportDashboardFilters;
use Modules\SupportDashboard\Service\SupportDashboardService;
use Modules\SupportDashboard\Service\SupportHistoryPeriod;
use Modules\SupportDashboard\Service\SupportInstallationExporter;
use Twig\Environment;

/**
 * `/support-dashboard` (`role_min: superadmin`) — the receiver's view of
 * every installation reporting to it (ARCHITECTURE.md §8.50).
 *
 * View state lives entirely in the query string: no cookie, no local
 * storage, no session. Arriving with no query string always yields the same
 * default view — active installations only, newest report first.
 */
class SupportDashboardController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SupportDashboardService $dashboardService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $filters = SupportDashboardFilters::fromQuery($request->getQueryAll());

        // The history takes its own period and nothing else: it must not
        // move when a current-state filter does (ARCHITECTURE.md §8.51).
        return $this->render('@support_dashboard/index.html.twig', [
            'filters' => $filters,
            'view' => $this->dashboardService->buildView($filters),
            'history' => $this->dashboardService->buildHistory(
                SupportHistoryPeriod::fromQuery($request->getQueryAll())
            ),
            'history_choices' => SupportHistoryPeriod::CHOICES,
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

    /**
     * `GET /support-dashboard/export` — XLSX of the **currently filtered
     * set**, not the page on screen. The filters travel in the query string
     * exactly as they do for the page, so the export is reproducible from
     * its own URL and can never drift from what the table showed.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $filters = SupportDashboardFilters::fromQuery($request->getQueryAll());
        $xlsx = SupportInstallationExporter::build($this->dashboardService->filteredRows($filters));

        return (new Response($xlsx))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="installations-support.xlsx"')
            ->setHeader('Content-Length', (string) strlen($xlsx));
    }

    /**
     * `POST /support-dashboard/installations/{id}/delete` — a superadmin
     * removing one record before retention would. The confirmation dialog
     * lives on the page; this end only enforces the CSRF token, because a
     * confirmation a caller can skip is not a control.
     *
     * The deletion is total (identifier, URL, payload, credential hash) and
     * leaves finalised monthly aggregates untouched.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/support-dashboard')) !== null) {
            return $guard;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->dashboardService->delete($id)) {
            // The id is the receiver's own row number, not the reporting
            // installation's identifier: nothing here identifies a unit.
            $this->journalService->log(
                'support_dashboard',
                'support_installation_deleted',
                'info',
                'Enregistrement d\'installation supprimé manuellement.',
                ['installation_row_id' => $id]
            );
        }

        return $this->redirect('/support-dashboard');
    }
}
