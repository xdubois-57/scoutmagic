<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Service\FinanceException;
use Modules\Finance\Service\ReceiptExtractionService;
use Modules\Finance\Service\ReceiptService;

class ReceiptController extends AbstractController
{
    private const MAX_SIZE_BYTES = 15 * 1024 * 1024;

    public function __construct(
        protected \Twig\Environment $twig,
        private AttachmentRepository $attachmentRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private ReceiptService $receiptService,
        private ReceiptExtractionService $receiptExtractionService,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        $attachments = $this->attachmentRepository->findActiveOrdered();
        $associatedIds = $this->transactionAttachmentRepository->findAssociatedAttachmentIds();

        $rows = [];
        foreach ($attachments as $attachment) {
            $transactionIds = $this->transactionAttachmentRepository->findTransactionIdsForAttachment($attachment->id);
            $rows[] = [
                'attachment' => $attachment,
                'is_pending' => !in_array($attachment->id, $associatedIds, true),
                'movement_count' => count($transactionIds),
            ];
        }

        return $this->render('@finance/receipts/list.html.twig', ['rows' => $rows]);
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): Response
    {
        $replaceId = $request->getQuery('replace');

        return $this->render('@finance/receipts/form.html.twig', [
            'replace_id' => $replaceId !== null ? (int) $replaceId : null,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Jeton CSRF invalide.']);
        }

        $file = $request->getFile('receipt');
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Aucun fichier fourni ou erreur lors du téléversement.']);
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_SIZE_BYTES) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Le fichier dépasse la taille maximale autorisée (15 Mo).']);
        }

        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Impossible de lire le fichier envoyé.']);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: (string) ($file['type'] ?? '');

        $amountRaw = (string) $request->getBody('amount', '');
        $amount = $amountRaw !== '' ? (float) str_replace(',', '.', $amountRaw) : null;
        $date = (string) $request->getBody('date', '');
        $date = $date !== '' ? $date : null;

        try {
            $attachment = $this->receiptService->upload($content, $mimeType, (string) $file['name'], $amount, $date, AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => $e->getMessage()]);
        }

        $this->receiptExtractionService->scheduleExtraction($attachment->id);

        $this->journalService->log('finance', 'receipt_uploaded', 'info', 'Reçu ajouté', ['attachment_id' => $attachment->id], AuthSession::getUserAccountId());

        return $this->redirect('/finance/receipts');
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
        if ($this->attachmentRepository->findById($id) === null) {
            return $this->json(['success' => false, 'error' => 'Reçu introuvable.'], 404);
        }

        $amountRaw = $data['suggested_amount'] ?? null;
        $amount = $amountRaw !== null && $amountRaw !== '' ? (float) $amountRaw : null;
        $date = !empty($data['suggested_date']) ? (string) $data['suggested_date'] : null;

        $suggestedSource = ($amount !== null || $date !== null) ? Attachment::SUGGESTED_SOURCE_MANUAL : null;
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
            return $this->render('@finance/receipts/form.html.twig', ['error' => 'Jeton CSRF invalide.', 'replace_id' => $id]);
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

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: (string) ($file['type'] ?? '');

        try {
            $attachment = $this->receiptService->replace($id, $content, $mimeType, (string) $file['name'], AuthSession::getUserAccountId());
        } catch (FinanceException $e) {
            return $this->render('@finance/receipts/form.html.twig', ['error' => $e->getMessage(), 'replace_id' => $id]);
        }

        $this->receiptExtractionService->scheduleExtraction($attachment->id);

        $this->journalService->log('finance', 'receipt_replaced', 'info', 'Reçu remplacé', ['old_attachment_id' => $id, 'new_attachment_id' => $attachment->id], AuthSession::getUserAccountId());

        return $this->redirect('/finance/receipts');
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
        $transactionIds = array_map('intval', (array) ($data['transaction_ids'] ?? []));

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
        $transactionId = (int) ($data['transaction_id'] ?? 0);

        $this->receiptService->dissociate($id, $transactionId);

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
