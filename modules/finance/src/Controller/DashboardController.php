<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Repository\FiscalYear;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Repository\TransactionRepository;

class DashboardController extends AbstractController
{
    private const RECENT_MOVEMENTS_LIMIT = 10;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private BalanceService $balanceService,
        private TransactionRepository $transactionRepository,
        private ReceiptService $receiptService
    ) {
    }

    /**
     * GET /finance — landing page: account + fiscal-year pickers driving
     * a balance, a per-category income/expense breakdown, three Chart.js
     * graphs, the most recent movements, and an alert banner.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accounts = $this->financeService->getAccountsForUser($role);
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));

        if ($account === null) {
            return $this->render('@finance/dashboard.html.twig', ['accounts' => [], 'no_accounts' => true]);
        }

        $fiscalYears = $this->financeService->getFiscalYears();
        $fiscalYear = $this->resolveSelectedFiscalYear($request, $fiscalYears);

        $balance = $this->balanceService->getBalanceAt($account, new \DateTimeImmutable('today'));

        $categorySummary = [];
        $balanceEvolution = [];
        $recentMovements = [];
        $uncategorizedCount = 0;

        if ($fiscalYear !== null) {
            $categorySummary = $this->financeService->getCategorySummary($account->id, $fiscalYear->id);
            $balanceEvolution = $this->financeService->getBalanceEvolution($account->id, $fiscalYear->id);
            $recentMovements = array_slice(
                $this->transactionRepository->findFiltered([$account->id], $fiscalYear->id, null, null),
                0,
                self::RECENT_MOVEMENTS_LIMIT
            );
            $uncategorizedCount = $this->transactionRepository->countUncategorized($account->id, $fiscalYear->id);
        }

        $bilan = ['income' => 0.0, 'expense' => 0.0, 'total' => 0.0];
        foreach ($categorySummary as $row) {
            $bilan['income'] += $row['income'];
            $bilan['expense'] += $row['expense'];
            $bilan['total'] += $row['total'];
        }

        return $this->render('@finance/dashboard.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'fiscal_years' => $fiscalYears,
            'selected_fiscal_year' => $fiscalYear,
            'balance' => $balance,
            'category_summary' => $categorySummary,
            'bilan' => $bilan,
            'balance_evolution' => $balanceEvolution,
            'recent_movements' => $recentMovements,
            'uncategorized_count' => $uncategorizedCount,
            'pending_receipts_count' => count($this->receiptService->listPending($account->id)),
        ]);
    }

    /**
     * @param FiscalYear[] $fiscalYears
     */
    private function resolveSelectedFiscalYear(Request $request, array $fiscalYears): ?FiscalYear
    {
        if ($fiscalYears === []) {
            return null;
        }

        $requestedId = $request->getQuery('fiscal_year_id');
        if ($requestedId !== null) {
            foreach ($fiscalYears as $fiscalYear) {
                if ($fiscalYear->id === (int) $requestedId) {
                    return $fiscalYear;
                }
            }
        }

        foreach ($fiscalYears as $fiscalYear) {
            if ($fiscalYear->isCurrent) {
                return $fiscalYear;
            }
        }

        return $fiscalYears[0];
    }
}
