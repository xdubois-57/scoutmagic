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
use Modules\Finance\Service\ReceiptService;

class ReceiptController extends AbstractController
{
    private const MAX_SIZE_BYTES = 15 * 1024 * 1024;
    private const MAX_FILES_PER_UPLOAD = 10;
    private const PER_PAGE = 30;

    /**
     * How the sorting pile is addressed in a query string and in the
     * account picker.
     *
     * A word and not `0`: an id of zero is what a mis-cast or an empty
     * parameter produces, and « les reçus que personne ne réclame » is not
     * somewhere anybody should arrive by accident.
     */
    public const UNASSIGNED_KEY = 'unassigned';

    /** resolveSelection()'s "nothing this session may look at". */
    private const SELECTION_NONE = false;

    public function __construct(
        protected \Twig\Environment $twig,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private TransactionRepository $transactionRepository,
        private FinanceService $financeService,
        private ReceiptService $receiptService,
        private FirstReceiptResolver $firstReceiptResolver,
        private JournalService $journalService,
        /**
         * The mail this module proposed on and nobody answered (§8.58).
         * Null without `inbound_mail`, and the page then has no « Courrier
         * à trier » block — which is exactly true.
         */
        private ?\Modules\InboundMail\Api\InboundMailInterface $inboundMail = null
    ) {
    }

    /**
     * The references this session may answer for: its visible accounts,
     * and the sorting pile when it may sort it. What `confirmCandidate()`
     * is scoped by, so a proposition towards an account this treasurer
     * cannot open is refused whatever the screen posted.
     *
     * @param \Modules\Finance\Repository\Account[] $accounts
     * @return string[]
     */
    private static function mailReferences(array $accounts, bool $maySortThePile): array
    {
        $references = array_map(
            static fn($account): string => \Modules\Finance\Mail\FinanceMessageConsumer::referenceFor($account->id),
            $accounts
        );
        if ($maySortThePile) {
            $references[] = \Modules\Finance\Mail\FinanceMessageConsumer::REFERENCE_UNKNOWN;
        }

        return $references;
    }

    /**
     * The messages carrying a standing proposition towards one of this
     * session's accounts, with those propositions.
     *
     * @param string[] $references
     * @return list<array{
     *     message: \Modules\InboundMail\Api\InboundMessage,
     *     candidates: \Modules\InboundMail\Api\MessageCandidate[]
     * }>
     */
    private function mailPropositions(array $references): array
    {
        if ($this->inboundMail === null || $references === []) {
            return [];
        }

        $consumerId = \Modules\Finance\Mail\FinanceMessageConsumer::CONSUMER_ID;
        $messages = $this->inboundMail->findForTriage($consumerId, $references);
        if ($messages === []) {
            return [];
        }

        $byMessage = $this->inboundMail->findCandidatesFor(
            $consumerId,
            array_map(static fn($message) => $message->id, $messages)
        );

        $rows = [];
        foreach ($messages as $message) {
            $candidates = array_values(array_filter(
                $byMessage[$message->id] ?? [],
                static fn($candidate): bool => in_array($candidate->businessReference, $references, true)
            ));
            if ($candidates !== []) {
                $rows[] = ['message' => $message, 'candidates' => $candidates];
            }
        }

        return $rows;
    }

    /**
     * POST /finance/receipts/courrier/{id}/confirmation — a treasurer says
     * yes to what the module suspected: the attachment becomes a receipt
     * on that account, filed by them.
     *
     * @param array<string, string> $params
     */
    public function confirmMailProposition(Request $request, array $params): Response
    {
        return $this->decideMailProposition($request, $params, true);
    }

    /**
     * POST /finance/receipts/courrier/{id}/rejet
     *
     * @param array<string, string> $params
     */
    public function dismissMailProposition(Request $request, array $params): Response
    {
        return $this->decideMailProposition($request, $params, false);
    }

    /**
     * @param array<string, string> $params
     */
    private function decideMailProposition(Request $request, array $params, bool $confirm): Response
    {
        if (($guard = $this->guardCsrf($request, '/finance/receipts')) !== null) {
            return $guard;
        }

        if ($this->inboundMail === null) {
            return $this->notFound();
        }

        $role = Role::fromString(AuthSession::getRole());
        $references = self::mailReferences(
            $this->financeService->getAccountsForUser($role),
            $this->financeService->isUnassignedReceiptVisibleTo($role)
        );
        $messageId = (int) ($params['id'] ?? 0);
        $candidateId = (int) $request->getBody('candidate_id', 0);
        $consumerId = \Modules\Finance\Mail\FinanceMessageConsumer::CONSUMER_ID;

        $done = $confirm
            ? $this->inboundMail->confirmCandidate($consumerId, $references, $messageId, $candidateId,
                AuthSession::getUserAccountId())
            : $this->inboundMail->dismissCandidate($consumerId, $references, $messageId, $candidateId);

        FlashMessage::set(
            $done ? 'success' : 'error',
            $done
                ? ($confirm ? 'Message rattaché : la pièce jointe est enregistrée comme reçu.' : 'Proposition écartée.')
                : 'Cette proposition n\'existe plus.'
        );

        return $this->redirect('/finance/receipts');
    }

    /**
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accounts = $this->financeService->getAccountsForUser($role);
        $maySortThePile = $this->financeService->isUnassignedReceiptVisibleTo($role);
        $unassignedCount = $maySortThePile ? $this->attachmentRepository->countActiveUnassigned() : 0;

        $selection = $this->resolveSelection($request->getQuery('account_id'), $role, $maySortThePile);

        // Only truly nothing to show. A session that may sort the pile has
        // a page even with no account of its own — which is the whole point
        // of the pile being a place rather than an account.
        if ($selection === self::SELECTION_NONE) {
            return $this->render('@finance/receipts/list.html.twig', ['accounts' => [], 'no_accounts' => true]);
        }

        $account = $selection instanceof \Modules\Finance\Repository\Account ? $selection : null;
        $accountId = $account?->id;

        $pendingOnly = $request->getQuery('pending') === '1';
        $search = trim((string) $request->getQuery('q', ''));
        $page = max(1, (int) $request->getQuery('page', 1));

        $totalPages = max(1, (int) ceil(
            $this->attachmentRepository->countFilteredForAccount($accountId, $pendingOnly,
                $search !== '' ? $search : null) / self::PER_PAGE
        ));
        $page = min($page, $totalPages);

        return $this->render('@finance/receipts/list.html.twig', [
            'accounts' => $accounts,
            'selected_account' => $account,
            'selected_key' => $account === null ? self::UNASSIGNED_KEY : $account->id,
            'may_sort_the_pile' => $maySortThePile,
            'mail_propositions' => $this->mailPropositions(self::mailReferences($accounts, $maySortThePile)),
            'unassigned_count' => $unassignedCount,
            'rows' => $this->buildRows($accountId, $pendingOnly, $search, $page),
            'pending_only' => $pendingOnly,
            'search' => $search,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * What `account_id` names: an account, the sorting pile (null), or
     * nothing this session may look at.
     *
     * The pile is addressed by a word rather than by an id, because it has
     * none — and by a word rather than by `0`, so that a stray `?account_id=`
     * or a mis-cast integer can never land on it by accident.
     *
     * @return \Modules\Finance\Repository\Account|null|self::SELECTION_NONE
     */
    private function resolveSelection(?string $requested, Role $role, bool $maySortThePile): mixed
    {
        if ($requested === self::UNASSIGNED_KEY) {
            return $maySortThePile ? null : self::SELECTION_NONE;
        }

        $account = $this->financeService->resolveSelectedAccount($role, $requested);
        if ($account !== null) {
            return $account;
        }

        // No account at all. The pile is the page's remaining content when
        // this session may see it, rather than an empty state that hides
        // receipts somebody is meant to sort.
        return $maySortThePile ? null : self::SELECTION_NONE;
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
        $requested = $request->getQuery('account_id');

        $selection = $this->resolveSelection(
            $requested,
            $role,
            $this->financeService->isUnassignedReceiptVisibleTo($role)
        );

        // The pile is only ever reached by asking for it by name: a page
        // whose account picker no longer offers an account must not silently
        // start listing receipts that belong to nobody.
        if ($selection === self::SELECTION_NONE || ($selection === null && $requested !== self::UNASSIGNED_KEY)) {
            return $this->json(['success' => false, 'error' => 'Compte introuvable.'], 404);
        }

        $accountId = $selection instanceof \Modules\Finance\Repository\Account ? $selection->id : null;

        $pendingOnly = $request->getQuery('pending') === '1';
        $search = trim((string) $request->getQuery('q', ''));
        $page = max(1, (int) $request->getQuery('page', 1));

        $total = $this->attachmentRepository->countFilteredForAccount($accountId, $pendingOnly,
            $search !== '' ? $search : null);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);

        $rows = $this->buildRows($accountId, $pendingOnly, $search, $page);

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
    private function buildRows(?int $accountId, bool $pendingOnly, string $search, int $page): array
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
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
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
            $attachment = $this->receiptService->upload($content, $mimeType, $file['name'], $accountId, null, null,
                AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $e->getMessage();
        }

        // The extraction is queued by Service\ReceiptService::store()
        // itself, so that every way in gets it — including the one with
        // no controller at all, a receipt arriving by e-mail.
        $this->journalService->log('finance', 'receipt_uploaded', 'info', 'Reçu ajouté',
            ['attachment_id' => $attachment->id, 'account_id' => $accountId], AuthSession::getUserAccountId());

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

        $this->journalService->log('finance', 'receipt_deleted', 'info', 'Reçu supprimé (archivé)',
            ['attachment_id' => $id], AuthSession::getUserAccountId());

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function replace(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => self::SESSION_EXPIRED_MESSAGE, 'replace_id' => $id]);
        }

        $attachment = $this->requireVisibleAttachment($id);
        if ($attachment instanceof Response) {
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => 'Reçu introuvable ou inaccessible.', 'replace_id' => $id]);
        }

        $file = $request->getFile('receipt');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.', 'replace_id' => $id]);
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE_BYTES) {
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => 'Le fichier dépasse la taille maximale autorisée (15 Mo).', 'replace_id' => $id]);
        }

        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false) {
            return $this->render('@finance/receipts/form.html.twig',
                ['error' => 'Impossible de lire le fichier envoyé.', 'replace_id' => $id]);
        }

        // Detection is the sole source of truth — no client-declared fallback
        // (audit M9); a failure yields a sentinel the allowlist rejects.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);
        $mimeType = $detected !== false ? $detected : 'application/octet-stream';

        try {
            $newAttachment = $this->receiptService->replace($id, $content, $mimeType, (string) $file['name'],
                AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $this->render(
                '@finance/receipts/form.html.twig',
                ['error' => $e->getMessage(), 'replace_id' => $id]
            );
        }

        $this->journalService->log('finance', 'receipt_replaced', 'info', 'Reçu remplacé',
            ['old_attachment_id' => $id, 'new_attachment_id' => $newAttachment->id], AuthSession::getUserAccountId());

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

        $this->journalService->log('finance', 'receipt_associated', 'info', 'Reçu associé à des mouvements',
            ['attachment_id' => $id, 'transaction_ids' => $transactionIds], AuthSession::getUserAccountId());

        return $this->json(['success' => true]);
    }

    /**
     * POST /finance/receipts/{id}/account — move a receipt onto another
     * account, or onto the sorting pile (`account_id: null`).
     *
     * **Both ends are authorized, and they are two different questions.**
     * requireVisibleAttachment() says the caller may touch the receipt
     * where it is now; isAccountVisibleTo() says they may put it where
     * they are asking. Checking only the first would let a section
     * treasurer file their own receipt onto another section's account —
     * and then read that account's movements back through movements(),
     * which authorizes against the receipt alone.
     *
     * The movement associations that go are counted and journaled: a
     * receipt losing its filing has to be explainable afterwards, and the
     * dialog that warned about it is not evidence.
     *
     * @param array<string, string> $params
     */
    public function changeAccount(Request $request, array $params): Response
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

        $requested = $data['account_id'] ?? null;
        $newAccountId = $requested === null || $requested === '' || $requested === 'unassigned'
            ? null
            : (int) $requested;

        $role = Role::fromString(AuthSession::getRole());
        $targetAllowed = $newAccountId === null
            ? $this->financeService->isUnassignedReceiptVisibleTo($role)
            : $this->financeService->isAccountVisibleTo($this->financeService->getAccount($newAccountId), $role);

        if (!$targetAllowed) {
            // "No such account" and "not yours" answer the same thing, so a
            // caller never learns which accounts exist (SECURITY.md §3).
            return $this->json(['success' => false, 'error' => 'Compte introuvable.'], 404);
        }

        try {
            $dropped = $this->receiptService->reassign($id, $newAccountId);
        } catch (FinanceException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'finance',
            'receipt_account_changed',
            'info',
            'Reçu déplacé vers un autre compte',
            [
                'attachment_id' => $id,
                'from_account_id' => $attachment->accountId,
                'to_account_id' => $newAccountId,
                'associations_dropped' => $dropped,
            ],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'associations_dropped' => $dropped]);
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
     * Loads the attachment and verifies it is visible to the current role
     * — its account's rule when it has one, the sorting pile's when it
     * does not. Every mutation endpoint goes through this so a
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

        $role = Role::fromString(AuthSession::getRole());

        // No account is not "no owner, therefore nobody": it is the unit's
        // sorting pile, and it has a rule of its own — narrower than this
        // page's floor, because an unsorted receipt may belong to any
        // section. This used to deny outright, which was right while
        // nothing could produce such a row on purpose and wrong now that
        // Service\ReceiptService::uploadUnattributed() does.
        $allowed = $attachment->accountId === null
            ? $this->financeService->isUnassignedReceiptVisibleTo($role)
            : $this->financeService->isAccountVisibleTo(
                $this->financeService->getAccount($attachment->accountId),
                $role
            );

        if (!$allowed) {
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
