<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYear;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;
use Modules\Finance\Repository\TransactionRepository;

class DashboardController extends AbstractController
{
    private const RECENT_MOVEMENTS_LIMIT = 10;
    private const PENDING_RECEIPTS_LIMIT = 3;
    private const LOWEST_BALANCE_WINDOW_MONTHS = 18;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private BalanceService $balanceService,
        private TransactionRepository $transactionRepository,
        private ReceiptService $receiptService,
        private CategoryRepository $categoryRepository,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private StatementImportRepository $statementImportRepository
    ) {
    }

    /**
     * GET /finance — landing page: account + fiscal-year filter (both
     * required, always a concrete default) drives a balance, a
     * per-category income/expense breakdown, three Chart.js graphs, and
     * a movements list. The same filter row also carries an optional
     * category (with an explicit "Non catégorisé" choice) and a
     * free-text search — those two only ever narrow the movements list,
     * never the balance/bilan/charts, which stay whole-fiscal-year
     * aggregates. The search looks at a movement's own label/comment
     * (both encrypted — matched by decrypting every filtered movement in
     * PHP, never via SQL LIKE) and, when a movement has a linked
     * receipt, that receipt's filename/merchant/description too
     * (merchant/description are encrypted the same way).
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

        $categoryParam = (string) $request->getQuery('category_id', 'all');
        $uncategorizedOnly = $categoryParam === 'none';
        $categoryId = (!$uncategorizedOnly && $categoryParam !== 'all' && $categoryParam !== '') ? (int) $categoryParam : null;
        $search = trim((string) $request->getQuery('q', ''));

        $balance = $this->balanceService->getBalanceAt($account, new \DateTimeImmutable('today'));
        $lowestBalance18Months = $this->balanceService->getLowestBalanceSince(
            $account,
            (new \DateTimeImmutable('today'))->modify('-' . self::LOWEST_BALANCE_WINDOW_MONTHS . ' months')
        );

        $categorySummary = [];
        $balanceEvolution = [];
        $recentMovements = [];
        $uncategorizedCount = 0;
        $netCategoryBreakdown = ['positive' => [], 'negative' => []];

        if ($fiscalYear !== null) {
            $categorySummary = $this->financeService->getCategorySummary($account->id, $fiscalYear->id);
            $balanceEvolution = $this->financeService->getBalanceEvolution($account->id, $fiscalYear->id);
            $recentMovements = array_slice(
                $this->findMatchingMovements($account->id, $fiscalYear->id, $categoryId, $uncategorizedOnly, $search),
                0,
                self::RECENT_MOVEMENTS_LIMIT
            );
            $uncategorizedCount = $this->transactionRepository->countUncategorized($account->id, $fiscalYear->id);
            $netCategoryBreakdown = $this->financeService->buildNetCategoryBreakdown($categorySummary);
        }

        $bilan = ['income' => 0.0, 'expense' => 0.0, 'total' => 0.0];
        foreach ($categorySummary as $row) {
            $bilan['income'] += $row['income'];
            $bilan['expense'] += $row['expense'];
            $bilan['total'] += $row['total'];
        }

        $allPendingReceipts = $this->receiptService->listPending($account->id);
        $pendingReceipts = array_slice($allPendingReceipts, 0, self::PENDING_RECEIPTS_LIMIT);

        return $this->render('@finance/dashboard.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'fiscal_years' => $fiscalYears,
            'selected_fiscal_year' => $fiscalYear,
            'categories' => $this->categoryRepository->findAllOrdered(),
            'filter_category_id' => $categoryParam,
            'filter_search' => $search,
            'balance' => $balance,
            'lowest_balance_18_months' => $lowestBalance18Months,
            'last_import' => $this->statementImportRepository->findMostRecentForAccount($account->id),
            'movements_count' => $this->transactionRepository->countByAccountId($account->id),
            'receipts_count' => $this->attachmentRepository->countActiveByAccountId($account->id),
            'category_summary' => $categorySummary,
            'bilan' => $bilan,
            'net_category_breakdown' => $netCategoryBreakdown,
            'balance_evolution' => $balanceEvolution,
            'recent_movements' => $recentMovements,
            'uncategorized_count' => $uncategorizedCount,
            'pending_receipts' => $pendingReceipts,
            'pending_receipts_count' => count($allPendingReceipts),
        ]);
    }

    /**
     * @return Transaction[]
     */
    private function findMatchingMovements(
        int $accountId,
        int $fiscalYearId,
        ?int $categoryId,
        bool $uncategorizedOnly,
        string $search
    ): array {
        $movements = $this->transactionRepository->findFiltered([$accountId], $fiscalYearId, $categoryId, null, $uncategorizedOnly);

        if ($search === '') {
            return $movements;
        }

        return array_values(array_filter(
            $movements,
            fn(Transaction $movement) => $this->matchesSearch($movement, $search)
        ));
    }

    private function matchesSearch(Transaction $movement, string $search): bool
    {
        if ($movement->matchesTextSearch($search)) {
            return true;
        }

        $attachmentIds = $this->transactionAttachmentRepository->findAttachmentIdsForTransaction($movement->id);
        if ($attachmentIds === []) {
            return false;
        }

        foreach ($this->attachmentRepository->findByIds($attachmentIds) as $attachment) {
            if ($this->attachmentMatchesSearch($attachment, $search)) {
                return true;
            }
        }

        return false;
    }

    private function attachmentMatchesSearch(Attachment $attachment, string $search): bool
    {
        if (mb_stripos($attachment->originalFilename, $search) !== false) {
            return true;
        }
        if ($attachment->suggestedLabel !== null && mb_stripos($attachment->suggestedLabel, $search) !== false) {
            return true;
        }
        return $attachment->suggestedDescription !== null && mb_stripos($attachment->suggestedDescription, $search) !== false;
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
