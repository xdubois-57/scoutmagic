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
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ReceiptService;

class MovementController extends AbstractController
{
    private const PER_PAGE = 50;
    private const SUGGESTION_AMOUNT_TOLERANCE_RATIO = 0.10;
    private const SUGGESTION_DATE_TOLERANCE_DAYS = 3;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private TransactionRepository $transactionRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private ReceiptService $receiptService,
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
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));

        if ($account === null) {
            return $this->render('@finance/movements/list.html.twig', ['accounts' => [], 'no_accounts' => true]);
        }

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
            [$account->id],
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

        $attachmentCounts = $this->transactionAttachmentRepository->countByTransactionIds(
            array_map(fn(Transaction $transaction) => $transaction->id, $movements)
        );

        $pendingReceipts = $this->receiptService->listPending($account->id);

        $rows = [];
        foreach ($movements as $movement) {
            $count = $attachmentCounts[$movement->id] ?? 0;
            $rows[] = [
                'movement' => $movement,
                'attachment_count' => $count,
                'suggested_receipt' => $count === 0 ? $this->findSuggestedReceipt($movement, $pendingReceipts) : null,
            ];
        }

        return $this->render('@finance/movements/list.html.twig', [
            'accounts' => $visibleAccounts,
            'selected_account' => $account,
            'fiscal_years' => $this->fiscalYearRepository->findAllOrdered(),
            'categories' => $this->categoryRepository->findAllOrdered(),
            'categories_by_id' => $categoriesById,
            'rows' => $rows,
            'total_count' => $totalCount,
            'page' => $page,
            'total_pages' => $totalPages,
            'filter_fiscal_year_id' => $fiscalYearId,
            'filter_category_id' => $categoryId,
            'filter_search' => $search,
            'pending_receipts' => $pendingReceipts,
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
     * GET /finance/movements/{id}/attachments — receipts linked to a
     * movement, for the 📎 panel on the movements page.
     *
     * @param array<string, string> $params
     */
    public function attachments(Request $request, array $params): Response
    {
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

        $attachmentIds = $this->transactionAttachmentRepository->findAttachmentIdsForTransaction($id);
        $attachments = $this->attachmentRepository->findByIds($attachmentIds);

        return $this->json([
            'success' => true,
            'attachments' => array_map(fn(Attachment $attachment) => [
                'id' => $attachment->id,
                'file_id' => $attachment->fileId,
                'original_filename' => $attachment->originalFilename,
                'mime_type' => $attachment->mimeType,
                'suggested_amount' => $attachment->suggestedAmount,
                'suggested_label' => $attachment->suggestedLabel,
                'suggested_description' => $attachment->suggestedDescription,
            ], $attachments),
        ]);
    }

    /**
     * GET /finance/movements/search?q=... — small result set for the
     * receipts page's "Associer à un mouvement" picker.
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $visibleAccounts = $this->financeService->getAccountsForUser($role);
        $visibleAccountIds = array_map(fn($account) => $account->id, $visibleAccounts);

        $requestedAccountId = $request->getQuery('account_id');
        $accountIdsFilter = $visibleAccountIds;
        if ($requestedAccountId !== null) {
            $requestedAccountId = (int) $requestedAccountId;
            if (in_array($requestedAccountId, $visibleAccountIds, true)) {
                $accountIdsFilter = [$requestedAccountId];
            }
        }

        $query = trim((string) $request->getQuery('q', ''));
        $matches = $this->transactionRepository->findFiltered($accountIdsFilter, null, null, $query !== '' ? $query : null);

        return $this->json([
            'success' => true,
            'movements' => array_map(fn(Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => $transaction->transactionDate,
                'label' => $transaction->label,
                'amount' => $transaction->amount,
            ], array_slice($matches, 0, 20)),
        ]);
    }

    /**
     * @param Attachment[] $pendingReceipts
     */
    private function findSuggestedReceipt(Transaction $transaction, array $pendingReceipts): ?Attachment
    {
        $amount = abs($transaction->amount);
        $transactionDate = new \DateTimeImmutable($transaction->transactionDate);

        foreach ($pendingReceipts as $receipt) {
            if ($receipt->suggestedAmount === null || $receipt->suggestedDate === null) {
                continue;
            }

            $tolerance = $amount * self::SUGGESTION_AMOUNT_TOLERANCE_RATIO;
            if (abs($receipt->suggestedAmount - $amount) > $tolerance) {
                continue;
            }

            $receiptDate = new \DateTimeImmutable($receipt->suggestedDate);
            $daysDiff = (int) $transactionDate->diff($receiptDate)->format('%a');
            if ($daysDiff > self::SUGGESTION_DATE_TOLERANCE_DAYS) {
                continue;
            }

            return $receipt;
        }

        return null;
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
