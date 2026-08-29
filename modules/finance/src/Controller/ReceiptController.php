<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Service\IntegerInput;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\FirstReceiptResolver;
use Modules\Finance\Service\MovementPresenter;
use Modules\Finance\Service\ReceiptDateNormalizer;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptService;

class ReceiptController extends AbstractController
{
    private const MAX_SIZE_BYTES = 15 * 1024 * 1024;
    private const MAX_FILES_PER_UPLOAD = 10;
    private const PER_PAGE = 30;

    public function __construct(
        protected \Twig\Environment $twig,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private TransactionRepository $transactionRepository,
        private FinanceService $financeService,
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
        $accounts = $this->financeService->getAccountsForUser($role);
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));

        if ($account === null) {
            return $this->render('@finance/receipts/list.html.twig', ['accounts' => [], 'no_accounts' => true]);
        }

        $pendingOnly = $request->getQuery('pending') === '1';
        $search = trim((string) $request->getQuery('q', ''));
        $page = max(1, (int) $request->getQuery('page', 1));

        $totalPages = max(1, (int) ceil(
            $this->attachmentRepository->countFilteredForAccount($account->id, $pendingOnly, $search !== '' ? $search : null) / self::PER_PAGE
        ));
        $page = min($page, $totalPages);

        $rows = $this->buildRows($account->id, $pendingOnly, $search, $page);

        return $this->render('@finance/receipts/list.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'rows' => $rows,
            'pending_only' => $pendingOnly,
            'search' => $search,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * GET /finance/receipts/search — the receipts page's filter bar
     * (pending toggle, free-text search, pagination) reads from this via
     * fetch() rather than a full page reload, so the list refreshes as
     * the chef types (module spec: "the filter is global and not per
     * page" — filtering/pagination both happen in the SQL query, never
     * on a client-side slice of an already-fetched page).
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));
        if ($account === null) {
            return $this->json(['success' => false, 'error' => 'Compte introuvable.'], 404);
        }

        $pendingOnly = $request->getQuery('pending') === '1';
        $search = trim((string) $request->getQuery('q', ''));
        $page = max(1, (int) $request->getQuery('page', 1));

        $total = $this->attachmentRepository->countFilteredForAccount($account->id, $pendingOnly, $search !== '' ? $search : null);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $rows = $this->buildRows($account->id, $pendingOnly, $search, $page);

        return $this->json([
            'success' => true,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'receipts' => array_map(fn(array $row) => [
                'id' => $row['attachment']->id,
                'file_id' => $row['attachment']->fileId,
                'mime_type' => $row['attachment']->mimeType,
                'original_filename' => $row['attachment']->originalFilename,
                'uploaded_at' => $row['attachment']->uploadedAt,
                'suggested_amount' => $row['attachment']->suggestedAmount,
                'suggested_date' => $row['attachment']->suggestedDate,
                'suggested_label' => $row['attachment']->suggestedLabel,
                'suggested_description' => $row['attachment']->suggestedDescription,
                'suggested_source' => $row['attachment']->suggestedSource,
                'matching_ai_attempted' => $row['attachment']->matchingAiAttemptedAt !== null,
                'is_pending' => $row['is_pending'],
                'movement_count' => $row['movement_count'],
            ], $rows),
        ]);
    }

    /**
     * GET /finance/receipts/{id}/movements — backs the receipts page's
     * "N mouvement(s) lié(s)" dialog (the inverse of Controller\
     * MovementController::attachments(), which lists a movement's
     * receipts).
     *
     * @param array<string, string> $params
     */
    public function movements(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        $transactionIds = $this->transactionAttachmentRepository->findTransactionIdsForAttachment($id);
        // Defence in depth: Service\ReceiptService::associate() now refuses
        // to link a movement from another account, but a row written before
        // that check existed would otherwise still be listed here — and this
        // endpoint only ever authorized the caller against the receipt.
        $transactions = array_values(array_filter(
            $this->transactionRepository->findByIds($transactionIds),
            fn(Transaction $transaction) => $transaction->accountId === $attachment->accountId
        ));

        $firstReceipts = $this->firstReceiptResolver->resolve(array_map(fn(Transaction $t) => $t->id, $transactions));

        return $this->json([
            'success' => true,
            'movements' => array_map(fn(Transaction $t) => [
                'id' => $t->id,
                'date' => $t->transactionDate,
                'label' => $t->label,
                'amount' => $t->amount,
                'description' => MovementPresenter::description($t, $firstReceipts[$t->id] ?? null),
            ], $transactions),
        ]);
    }

    /**
     * @return array<int, array{attachment: Attachment, movement_count: int, is_pending: bool}>
     */
    private function buildRows(int $accountId, bool $pendingOnly, string $search, int $page): array
    {
        $results = $this->attachmentRepository->findFilteredForAccount(
            $accountId, $pendingOnly, $search !== '' ? $search : null, self::PER_PAGE, ($page - 1) * self::PER_PAGE
        );

        return array_map(fn(array $row) => [
            'attachment' => $row['attachment'],
            'movement_count' => $row['movement_count'],
            'is_pending' => $row['movement_count'] === 0,
        ], $results);
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accounts = $this->financeService->getAccountsForUser($role);
        $account = $this->financeService->resolveSelectedAccount($role, $request->getQuery('account_id'));
        $replaceId = $request->getQuery('replace');

        return $this->render('@finance/receipts/form.html.twig', [
            'accounts' => $accounts,
            'breadcrumb_trail' => [
                ['label' => 'Reçus', 'url' => '/finance/receipts'],
            ],
            'selected_account' => $account,
            'replace_id' => $replaceId !== null ? (int) $replaceId : null,
        ]);
    }

    /**
     * Accepts up to MAX_FILES_PER_UPLOAD files in one request (drag-drop
     * or multi-select on the form) — amount/date are never asked for
     * here, only ever known from Task\ExtractReceiptDataHandler's AI
     * extraction (or later manual correction via update()). Every file
     * is uploaded independently: one rejected file (bad type, too large)
     * never blocks the others from going through.
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => self::SESSION_EXPIRED_MESSAGE]);
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) $request->getBody('account_id', 0);
        $account = $this->financeService->getAccount($accountId);
        if (!$this->financeService->isAccountVisibleTo($account, $role)) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Compte invalide.']);
        }

        $files = $request->getFiles('receipts');
        if ($files === []) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
        }
        if (count($files) > self::MAX_FILES_PER_UPLOAD) {
            return $this->render('@finance/receipts/form.html.twig', [
                'error' => 'Vous ne pouvez pas envoyer plus de ' . self::MAX_FILES_PER_UPLOAD . ' reçus à la fois.',
            ]);
        }

        $uploadedCount = 0;
        $errors = [];

        foreach ($files as $file) {
            $error = $this->uploadOne($file, $accountId);
            if ($error !== null) {
                $errors[] = $file['name'] . ' : ' . $error;
                continue;
            }
            $uploadedCount++;
        }

        if ($uploadedCount === 0) {
            FlashMessage::set('error', implode(' ', $errors));
        } elseif ($errors === []) {
            FlashMessage::set('success', $uploadedCount > 1 ? "{$uploadedCount} reçus ajoutés." : 'Reçu ajouté.');
        } else {
            FlashMessage::set('warning', "{$uploadedCount} reçu(s) ajouté(s). " . implode(' ', $errors));
        }

        return $this->redirect('/finance/receipts?account_id=' . $accountId);
    }

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int, type: string} $file
     * @return string|null an error message, or null on success
     */
    private function uploadOne(array $file, int $accountId): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'erreur lors du téléversement.';
        }
        if ($file['size'] > self::MAX_SIZE_BYTES) {
            return 'dépasse la taille maximale autorisée (15 Mo).';
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return 'impossible de lire le fichier envoyé.';
        }

        // Never trust the client-declared $file['type'] as a fallback: it is
        // attacker-controlled and would bypass ReceiptService's MIME allowlist
        // (audit M9). A detection failure becomes a non-allowlisted sentinel,
        // so the service rejects it rather than storing/echoing a spoofed type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);
        $mimeType = $detected !== false ? $detected : 'application/octet-stream';

        try {
            $attachment = $this->receiptService->upload($content, $mimeType, $file['name'], $accountId, null, null, AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $e->getMessage();
        }

        $this->receiptExtractionService->scheduleExtraction($attachment->id);
        $this->journalService->log('finance', 'receipt_uploaded', 'info', 'Reçu ajouté', ['attachment_id' => $attachment->id, 'account_id' => $accountId], AuthSession::getUserAccountId());

        return null;
    }

    /**
     * PATCH /finance/receipts/{id} — edits the manually-entered suggested
     * amount/date only (the file itself is only ever changed via
     * replace()).
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
        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        // array_key_exists(), not ??/empty(): a PATCH that omits a field
        // must leave it alone, and only an explicitly-sent empty value
        // clears it. Reading with ?? made a partial request silently wipe
        // whatever Task\ExtractReceiptDataHandler had extracted — same
        // convention as Controller\MovementController::update().
        $amount = $attachment->suggestedAmount;
        $amountChanged = false;
        if (array_key_exists('suggested_amount', $data)) {
            $amountRaw = $data['suggested_amount'];
            if ($amountRaw === null || $amountRaw === '') {
                $amount = null;
            } elseif (!is_numeric($amountRaw)) {
                return $this->json(['success' => false, 'error' => 'Montant invalide.'], 400);
            } else {
                $amount = (float) $amountRaw;
            }
            $amountChanged = $amount !== $attachment->suggestedAmount;
        }

        $date = $attachment->suggestedDate;
        $dateChanged = false;
        if (array_key_exists('suggested_date', $data)) {
            $dateRaw = $data['suggested_date'];
            if ($dateRaw === null || $dateRaw === '') {
                $date = null;
            } else {
                // Never store an unparseable date: it reaches a DATE column
                // and, worse, new \DateTimeImmutable() in Service\
                // ReceiptMatchingService and Controller\MovementController,
                // where it would throw and break a whole page.
                $date = ReceiptDateNormalizer::normalize((string) $dateRaw);
                if ($date === null) {
                    return $this->json(['success' => false, 'error' => 'Date invalide.'], 400);
                }
            }
            $dateChanged = $date !== $attachment->suggestedDate;
        }

        // Only a real edit re-labels the suggestion as manual — re-saving a
        // receipt without touching its AI-extracted values must keep the
        // "(IA)" indicator the column exists to carry.
        $suggestedSource = $attachment->suggestedSource;
        if ($amountChanged || $dateChanged) {
            $suggestedSource = Attachment::SUGGESTED_SOURCE_MANUAL;
        }
        if ($amount === null && $date === null) {
            $suggestedSource = null;
        }

        $this->attachmentRepository->updateSuggestedData($id, $amount, $date, $suggestedSource);

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($params['id'] ?? 0);
        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        try {
            $this->receiptService->delete($id);
        } catch (FinanceException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log('finance', 'receipt_deleted', 'info', 'Reçu supprimé (archivé)', ['attachment_id' => $id], AuthSession::getUserAccountId());

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function replace(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => self::SESSION_EXPIRED_MESSAGE, 'replace_id' => $id]);
        }

        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Reçu introuvable ou inaccessible.', 'replace_id' => $id]);
        }

        $file = $request->getFile('receipt');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.', 'replace_id' => $id]);
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE_BYTES) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Le fichier dépasse la taille maximale autorisée (15 Mo).', 'replace_id' => $id]);
        }

        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Impossible de lire le fichier envoyé.', 'replace_id' => $id]);
        }

        // Detection is the sole source of truth — no client-declared fallback
        // (audit M9); a failure yields a sentinel the allowlist rejects.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);
        $mimeType = $detected !== false ? $detected : 'application/octet-stream';

        try {
            $newAttachment = $this->receiptService->replace($id, $content, $mimeType, (string) $file['name'], AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => $e->getMessage(), 'replace_id' => $id]);
        }

        $this->receiptExtractionService->scheduleExtraction($newAttachment->id);

        $this->journalService->log('finance', 'receipt_replaced', 'info', 'Reçu remplacé', ['old_attachment_id' => $id, 'new_attachment_id' => $newAttachment->id], AuthSession::getUserAccountId());

        return $this->redirect('/finance/receipts' . ($newAttachment->accountId !== null ? '?account_id=' . $newAttachment->accountId : ''));
    }

    /**
     * @param array<string, string> $params
     */
    public function associate(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($params['id'] ?? 0);
        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        $transactionIds = IntegerInput::idList($data['transaction_ids'] ?? []);
        if ($transactionIds === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        try {
            $this->receiptService->associate($id, $transactionIds);
        } catch (FinanceException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log('finance', 'receipt_associated', 'info', 'Reçu associé à des mouvements', ['attachment_id' => $id, 'transaction_ids' => $transactionIds], AuthSession::getUserAccountId());

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function dissociate(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($params['id'] ?? 0);
        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        $transactionId = (int) ($data['transaction_id'] ?? 0);

        $this->receiptService->dissociate($id, $transactionId);

        return $this->json(['success' => true]);
    }

    /**
     * Loads the attachment and verifies its account is visible to the
     * current role — every mutation endpoint goes through this so a
     * receipt tied to an account above the caller's role_min_view can
     * never be edited/deleted/associated, matching the download-side
     * enforcement already applied via the file's role_min.
     *
     * @return Attachment|Response an error Response to return as-is when denied
     */
    private function requireVisibleAttachment(int $attachmentId): Attachment|Response
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null) {
            return $this->json(['success' => false, 'error' => 'Reçu introuvable.'], 404);
        }

        // No account means no account to check the caller against, so there
        // is nothing that could authorize the mutation — deny rather than
        // fall through. ReceiptService::upload() always sets an accountId,
        // so this only bites on legacy/imported rows, but "unknown owner"
        // must never read as "anyone may edit it" (same fail-safe posture as
        // Core\File\FileAccessGuard's unregistered owner_type).
        if ($attachment->accountId === null) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $role = Role::fromString(AuthSession::getRole());
        $account = $this->financeService->getAccount($attachment->accountId);
        if (!$this->financeService->isAccountVisibleTo($account, $role)) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        return $attachment;
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        return $data;
    }
}
