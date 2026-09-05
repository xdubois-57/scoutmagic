<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Scheduler\SchedulerService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Task\PurgeCampaignFilesHandler;

/**
 * GET /config/finance — landing page: summary counts linking to the four
 * sub-pages (accounts/categories/rules/fiscal-years, each its own
 * controller+view), plus the "zone de danger" at the bottom (module spec
 * §"Page de configuration") since there is no dedicated view for it.
 */
class ConfigController extends AbstractController
{
    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private SchedulerService $schedulerService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->financeService->ensureDefaultAccountsForSections();
        $this->ensurePurgeScheduled();
        $this->ensureReconciliationScheduled();
        $this->ensureCampaignFilePurgeScheduled();

        $accounts = $this->financeService->getAllAccountsForConfig();

        return $this->render('@finance/config/index.html.twig', [
            'account_count' => count($accounts),
            'category_count' => count($this->financeService->getAllCategories()),
            'fiscal_year_count' => count($this->financeService->getFiscalYears()),
            'current_fiscal_year' => $this->financeService->getCurrentFiscalYear(),
            'accounts' => $accounts,
        ]);
    }

    /**
     * Ensures the monthly movement-purge task is scheduled — same
     * idempotent check-then-schedule pattern as
     * Modules\LlmConnector\Controller\ConfigController::ensureWeeklyRefreshScheduled().
     */
    private function ensurePurgeScheduled(): void
    {
        $existing = $this->schedulerService->find('finance', 'purge_old_movements', 'monthly');
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $this->schedulerService->schedule(
            'finance',
            'purge_old_movements',
            new \DateTimeImmutable('+1 month'),
            [],
            'monthly',
        );
    }

    /**
     * Ensures the nightly receivable-reconciliation task is scheduled —
     * the safety net under Service\ReceivableAllocationService, which
     * matters most on an installation whose movements and receivables
     * both predate the allocation model and have nothing written between
     * them. Same idempotent check-then-schedule shape as above.
     */
    private function ensureReconciliationScheduled(): void
    {
        $existing = $this->schedulerService->find('finance', 'reconcile_receivables', 'nightly');
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $this->schedulerService->schedule(
            'finance',
            'reconcile_receivables',
            new \DateTimeImmutable('tomorrow 04:00'),
            [],
            'nightly',
        );
    }

    /**
     * Ensures the daily campaign-file retention purge is scheduled. It
     * enforces an RGPD promise, so it must run even on an installation
     * where nobody creates campaigns any more — a retention hung off the
     * next campaign would keep its promise only while the unit keeps
     * making them.
     */
    private function ensureCampaignFilePurgeScheduled(): void
    {
        $existing = $this->schedulerService->find('finance', PurgeCampaignFilesHandler::TASK_KEY,
            PurgeCampaignFilesHandler::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $this->schedulerService->scheduleAfter(
            'finance',
            PurgeCampaignFilesHandler::TASK_KEY,
            PurgeCampaignFilesHandler::INTERVAL_SECONDS,
            [],
            PurgeCampaignFilesHandler::REFERENCE
        );
    }
}
