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
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\FirstReceiptResolver;
use Modules\Finance\Service\MovementPresenter;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptService;

class MovementController extends AbstractController
{
    private const PER_PAGE = 50;
    private const SUGGESTION_AMOUNT_TOLERANCE_RATIO = 0.10;
    private const SUGGESTION_DATE_TOLERANCE_DAYS = 3;
    private const MAX_ATTACHMENT_SIZE_BYTES = 15 * 1024 * 1024;

    public function __construct(
        protected \Twig\Environment $twig,
        private FinanceService $financeService,
        private TransactionRepository $transactionRepository,
        private CategoryRepository $categoryRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private ReceiptService $receiptService,
        private ReceiptExtractionService $receiptExtractionService,
        private FirstReceiptResolver $firstReceiptResolver,
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

        $categoryParam = (string) $request->getQuery('category_id', 'all');
        $uncategorizedOnly = $categoryParam === 'none';
        $categoryId = (!$uncategorizedOnly && $categoryParam !== 'all' && $categoryParam !== '') ? (int) $categoryParam : null;

        $search = trim((string) $request->getQuery('q', ''));

        $allMatches = $this->transactionRepository->findFiltered(
            [$account->id],
            $fiscalYearId,
            $categoryId,
            $search !== '' ? $search : null,
            $uncategorizedOnly
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

        $movementIds = array_map(fn(Transaction $transaction) => $transaction->id, $movements);
        $attachmentCounts = $this->transactionAttachmentRepository->countByTransactionIds($movementIds);
        $firstReceiptsByMovementId = $this->firstReceiptResolver->resolve($movementIds);

        $pendingReceipts = $this->receiptService->listPending($account->id);

        $rows = [];
        foreach ($movements as $movement) {
            $count = $attachmentCounts[$movement->id] ?? 0;
            $firstReceipt = $firstReceiptsByMovementId[$movement->id] ?? null;
            $rows[] = [
                'movement' => $movement,
                'attachment_count' => $count,
                'suggested_receipt' => $count === 0 ? $this->findSuggestedReceipt($movement, $pendingReceipts) : null,
                'counterparty' => MovementPresenter::counterparty($movement, $firstReceipt, $account->name),
                'description' => MovementPresenter::description($movement, $firstReceipt),
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
            'filter_category_id' => $categoryParam,
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
                'suggested_date' => $attachment->suggestedDate,
                'suggested_label' => $attachment->suggestedLabel,
                'suggested_description' => $attachment->suggestedDescription,
            ], $attachments),
        ]);
    }

    /**
     * POST /finance/movements/{id}/attachments — the movements page's
     * receipt dialog can upload a brand new receipt directly (rather
     * than only associating an already-uploaded pending one), which is
     * then immediately associated with this movement in the same
     * request — one round trip instead of "upload on the receipts page,
     * come back here, associate". Mirrors Controller\ReceiptController::
     * uploadOne() (same size/MIME validation via Service\
     * ReceiptService::upload(), same AI extraction scheduling) but
     * returns JSON instead of a redirect, and associates immediately
     * instead of leaving the receipt pending.
     *
     * @param array<string, string> $params
     */
    public function uploadAttachment(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
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

        $files = $request->getFiles('receipt');
        if ($files === []) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier fourni ou erreur lors du téléversement.'], 400);
        }
        $file = $files[0];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->json(['success' => false, 'error' => 'Erreur lors du téléversement.'], 400);
        }
        if ($file['size'] > self::MAX_ATTACHMENT_SIZE_BYTES) {
            return $this->json(['success' => false, 'error' => 'Le fichier dépasse la taille maximale autorisée (15 Mo).'], 400);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return $this->json(['success' => false, 'error' => 'Impossible de lire le fichier envoyé.'], 400);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: $file['type'];

        try {
            $attachment = $this->receiptService->upload($content, $mimeType, $file['name'], $account->id, null, null, AuthSession::getUserAccountId());
            $this->receiptService->associate($attachment->id, [$id]);
        } catch (FinanceException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->receiptExtractionService->scheduleExtraction($attachment->id);
        $this->journalService->log(
            'finance', 'receipt_uploaded', 'info', 'Reçu ajouté et associé depuis la page des mouvements',
            ['attachment_id' => $attachment->id, 'transaction_id' => $id], AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'attachment_id' => $attachment->id]);
    }

    /**
     * GET /finance/movements/search?q=... — small result set for the
     * receipts page's "Associer à un mouvement" picker. Only ever offers
     * expenses (negative amount) — a receipt is proof of an expense,
     * never a candidate for an income movement. With no search text and
     * a $near_date (the receipt's own suggested date), returns the 10
     * movements closest to that date instead of an arbitrary/empty list
     * — the "most credible" candidates for that receipt. findFiltered()
     * already orders by transaction_date DESC, so a real search
     * (non-empty $q) keeps showing the most recent matches first.
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
        $nearDate = $this->parseDateOrNull($request->getQuery('near_date'));
        $matches = array_values(array_filter(
            // A receipt is proof of an expense — income never needs one,
            // so it's never worth offering as an association candidate.
            $this->transactionRepository->findFiltered($accountIdsFilter, null, null, $query !== '' ? $query : null),
            fn(Transaction $transaction) => $transaction->amount < 0
        ));

        if ($query === '' && $nearDate !== null) {
            usort(
                $matches,
                fn(Transaction $a, Transaction $b) =>
                    (new \DateTimeImmutable($a->transactionDate))->diff($nearDate)->days
                    <=> (new \DateTimeImmutable($b->transactionDate))->diff($nearDate)->days
            );
            $matches = array_slice($matches, 0, 10);
        } else {
            $matches = array_slice($matches, 0, 20);
        }

        $accountNamesById = [];
        foreach ($visibleAccounts as $visibleAccount) {
            $accountNamesById[$visibleAccount->id] = $visibleAccount->name;
        }
        $firstReceipts = $this->firstReceiptResolver->resolve(array_map(fn(Transaction $t) => $t->id, $matches));

        return $this->json([
            'success' => true,
            'movements' => array_map(fn(Transaction $transaction) => [
                'id' => $transaction->id,
                'date' => $transaction->transactionDate,
                'label' => $transaction->label,
                'amount' => $transaction->amount,
                'counterparty' => MovementPresenter::counterparty(
                    $transaction, $firstReceipts[$transaction->id] ?? null, $accountNamesById[$transaction->accountId] ?? ''
                ),
                'description' => MovementPresenter::description($transaction, $firstReceipts[$transaction->id] ?? null),
            ], $matches),
        ]);
    }

    private function parseDateOrNull(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
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
