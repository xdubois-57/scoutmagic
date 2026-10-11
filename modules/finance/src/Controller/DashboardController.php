<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\FiscalYear;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReconciliationService;

class DashboardController extends AbstractController
{
    private const LOWEST_BALANCE_WINDOW_MONTHS = 18;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private BalanceService $balanceService,
        private TransactionRepository $transactionRepository,
        private ReceiptService $receiptService,
        private StatementImportRepository $statementImportRepository,
        private ReconciliationService $reconciliationService,
        private ReceivableAllocationService $allocationService,
        private CampaignRepository $campaignRepository,
        private ScoutYearResolver $scoutYears
    ) {
    }


    /**
     * The year this session is actually looking at — its preview, its
     * staff year, or the public one (Core\ScoutYear\ScoutYearResolver).
     *
     * Not `getCurrentYear()`, the date-computed public year: the family
     * view of this very module already resolves the effective one
     * (Service\FamilyPaymentService), so the two halves of Finances
     * disagreed about which year they were counting during a preview and
     * across the 1st of September.
     */
    private function effectiveScoutYearId(Role $role): int
    {
        return $this->scoutYears->getEffectiveYear(ScoutYearSession::getPreviewId(), $role)->id;
    }

    /**
     * GET /finance — landing page for one account and one fiscal year.
     *
     * Two rows of four tiles (issue #838): what the account looks like —
     * balance, lowest balance, last statement, money still expected — and
     * then what is waiting for the treasurer — movements to categorise,
     * receipts to attach, payments to handle, open campaigns. Each action
     * tile is a count that links to the screen where it is dealt with,
     * which is why the dashboard no longer repeats those lists itself:
     * the banner, « Reçus en attente » and « Derniers mouvements en
     * attente d'action » said the same things a second and third time.
     *
     * Below the tiles, the fiscal year's charts and « Bilan par
     * catégorie ».
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
        $lowestBalance18Months = $this->balanceService->getLowestBalanceSince(
            $account,
            (new \DateTimeImmutable('today'))->modify('-' . self::LOWEST_BALANCE_WINDOW_MONTHS . ' months')
        );

        $categorySummary = [];
        $balanceEvolution = [];
        $uncategorizedCount = 0;
        $netCategoryBreakdown = ['positive' => [], 'negative' => []];

        if ($fiscalYear !== null) {
            $categorySummary = $this->financeService->getCategorySummary($account->id, $fiscalYear->id);
            $balanceEvolution = $this->financeService->getBalanceEvolution($account->id, $fiscalYear->id);
            $uncategorizedCount = $this->transactionRepository->countUncategorized($account->id, $fiscalYear->id);
            $netCategoryBreakdown = $this->financeService->buildNetCategoryBreakdown($categorySummary);
        }

        $bilan = ['income' => 0.0, 'expense' => 0.0, 'total' => 0.0];
        foreach ($categorySummary as $row) {
            $bilan['income'] += $row['income'];
            $bilan['expense'] += $row['expense'];
            $bilan['total'] += $row['total'];
        }

        // What still needs a treasurer's hand on « Paiements à traiter ».
        // Its build() runs the account's reconciliation pass first, so the
        // amount still expected, read right after, is read off allocations
        // that are up to date.
        $scoutYearId = $this->effectiveScoutYearId($role);
        $reconciliationCounts = $this->reconciliationService->pendingCounts($account->id, $scoutYearId, $role);

        return $this->render('@finance/dashboard.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'fiscal_years' => $fiscalYears,
            'selected_fiscal_year' => $fiscalYear,
            'balance' => $balance,
            'lowest_balance_18_months' => $lowestBalance18Months,
            'last_import' => $this->statementImportRepository->findMostRecentForAccount($account->id),
            'expected_cents' => $this->allocationService->amountStillExpectedCents($account->id),
            'uncategorized_count' => $uncategorizedCount,
            'pending_receipts_count' => count($this->receiptService->listPending($account->id)),
            'reconciliation_counts' => $reconciliationCounts,
            'reconciliation_pending' => array_sum($reconciliationCounts),
            // Counted for the scout year the campaigns list is opened on,
            // so the tile and the list it links to agree.
            'open_campaigns_count' => $this->campaignRepository->countOpen($account->id, $scoutYearId),
            'scout_year_id' => $scoutYearId,
            'category_summary' => $categorySummary,
            'bilan' => $bilan,
            'net_category_breakdown' => $netCategoryBreakdown,
            'balance_evolution' => $balanceEvolution,
            // The account picker (_nav.html.twig) changes what this page
            // shows without changing its URL structure (?account_id={id})
            // — the breadcrumb's own segment must reflect the current
            // selection, same as the section/calendar pickers elsewhere.
            'breadcrumb_current' => 'Finances · ' . $account->name,
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
