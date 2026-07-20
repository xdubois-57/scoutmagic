<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceService;

class MovementController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private TransactionRepository $transactionRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $visibleAccounts = $this->financeService->getAccountsForUser($role);
        $visibleAccountIds = array_map(fn($account) => $account->id, $visibleAccounts);

        $requestedAccountId = $request->getQuery('account_id');
        $accountId = $requestedAccountId !== null && $requestedAccountId !== '' ? (int) $requestedAccountId : null;
        $accountIdsFilter = ($accountId !== null && in_array($accountId, $visibleAccountIds, true))
            ? [$accountId]
            : $visibleAccountIds;

        $currentFiscalYear = $this->financeService->getCurrentFiscalYear();
        $fiscalYearParam = $request->getQuery('fiscal_year_id');
        if ($fiscalYearParam === null) {
            $fiscalYearId = $currentFiscalYear?->id;
        } elseif ($fiscalYearParam === 'all' || $fiscalYearParam === '') {
            $fiscalYearId = null;
        } else {
            $fiscalYearId = (int) $fiscalYearParam;
        }

        $categoryParam = $request->getQuery('category_id');
        $categoryId = ($categoryParam !== null && $categoryParam !== '' && $categoryParam !== 'all') ? (int) $categoryParam : null;

        $search = trim((string) $request->getQuery('q', ''));

        $allMatches = $this->transactionRepository->findFiltered(
            $accountIdsFilter,
            $fiscalYearId,
            $categoryId,
            $search !== '' ? $search : null
        );

        $page = max(1, (int) $request->getQuery('page', 1));
        $totalCount = count($allMatches);
        $totalPages = max(1, (int) ceil($totalCount / self::PER_PAGE));
        $page = min($page, $totalPages);
        $movements = array_slice($allMatches, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $categoriesById = [];
        foreach ($this->categoryRepository->findAllOrdered() as $category) {
            $categoriesById[$category->id] = $category;
        }

        return $this->render('@finance/movements/list.html.twig', [
            'accounts' => $visibleAccounts,
            'fiscal_years' => $this->fiscalYearRepository->findAllOrdered(),
            'categories' => $this->categoryRepository->findAllOrdered(),
            'categories_by_id' => $categoriesById,
            'movements' => $movements,
            'total_count' => $totalCount,
            'page' => $page,
            'total_pages' => $totalPages,
            'filter_account_id' => $accountId,
            'filter_fiscal_year_id' => $fiscalYearId,
            'filter_category_id' => $categoryId,
            'filter_search' => $search,
        ]);
    }

    /**
     * PATCH /finance/movements/{id} — category_id/comment/fiscal_year_id
     * only (module spec: "Refuse toute modification de amount,
     * transaction_date, label, bank_reference" — enforced structurally,
     * since Repository\TransactionRepository::updateEditableFields()
     * has no columns for those).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($params['id'] ?? 0);
        $transaction = $this->transactionRepository->findById($id);
        if ($transaction === null) {
            return $this->json(['success' => false, 'error' => 'Mouvement introuvable.'], 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        $account = $this->financeService->getAccount($transaction->accountId);
        if ($account === null || !$role->hasAccess(Role::fromString($account->roleMinView))) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $categoryId = $transaction->categoryId;
        if (array_key_exists('category_id', $data)) {
            $categoryId = $data['category_id'] !== null && $data['category_id'] !== '' ? (int) $data['category_id'] : null;
            if ($categoryId !== null && $this->categoryRepository->findById($categoryId) === null) {
                return $this->json(['success' => false, 'error' => 'Catégorie invalide.'], 400);
            }
        }

        $comment = $transaction->comment;
        if (array_key_exists('comment', $data)) {
            $comment = trim((string) $data['comment']);
            $comment = $comment !== '' ? $comment : null;
        }

        $fiscalYearId = $transaction->fiscalYearId;
        if (array_key_exists('fiscal_year_id', $data)) {
            $fiscalYearId = (int) $data['fiscal_year_id'];
            if ($this->fiscalYearRepository->findById($fiscalYearId) === null) {
                return $this->json(['success' => false, 'error' => 'Exercice invalide.'], 400);
            }
        }

        $this->transactionRepository->updateEditableFields($id, $categoryId, $comment, $fiscalYearId);

        $this->journalService->log(
            'finance',
            'movement_updated',
            'info',
            'Mouvement modifié',
            ['transaction_id' => $id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        return $data;
    }
}
