<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Scheduler\SchedulerService;
use Modules\Finance\Service\FinanceService;

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
     * Ensures the daily movement-purge task is scheduled — same
     * idempotent check-then-schedule pattern as
     * Modules\LlmConnector\Controller\ConfigController::ensureWeeklyRefreshScheduled().
     */
    private function ensurePurgeScheduled(): void
    {
        $existing = $this->schedulerService->find('finance', 'purge_old_movements', 'daily');
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $this->schedulerService->schedule('finance', 'purge_old_movements', new \DateTimeImmutable('+1 day'), [], 'daily');
    }
}
