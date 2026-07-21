<?php

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;
use Modules\LlmConnector\Service\LlmConnectorService;

/**
 * One-shot task (scheduled by Service\ReceiptExtractionService right
 * after an upload) — asks the configured LLM provider's OCR-tier model
 * (this module never names a model or provider; LlmConnectorService
 * falls back to the CHEAP tier when no model is assigned to OCR) to read
 * the receipt's amount/date/merchant/description, then writes
 * suggested_amount/suggested_date/suggested_label/suggested_description
 * back onto the attachment. Any failure (no provider, API error,
 * unparseable response) is journaled and otherwise silently absorbed —
 * a failed extraction never blocks the receipt from being used manually.
 */
class ExtractReceiptDataHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $attachmentId = (int) ($payload['attachment_id'] ?? 0);
        if ($attachmentId === 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $attachmentRepository = new AttachmentRepository($pdo);

        $attachment = $attachmentRepository->findById($attachmentId);
        if ($attachment === null || $attachment->status !== Attachment::STATUS_ACTIVE) {
            return;
        }

        $llmConnector = new LlmConnectorService(
            new ProviderRepository($pdo, $context->encryption),
            new ProviderModelRepository($pdo),
            $context->journal
        );

        if (!$llmConnector->isAvailable()) {
            return;
        }

        $fileStorage = new EncryptedFileStorageService(new FileRepository($pdo), $context->encryption, $context->storagePath);

        try {
            $content = $fileStorage->retrieve($attachment->fileId);
        } catch (\RuntimeException $e) {
            $this->logFailure($context, $attachmentId, $e->getMessage());
            return;
        }

        $request = new LlmRequest(
            tier: LlmTier::OCR,
            prompt: 'Extrait le montant total, la date, le nom du commerçant, et une description en une phrase '
                . "de l'objet de cet achat (par exemple « Achat de fournitures de bureau » ou « Cotisation "
                . 'trimestrielle ») à partir de ce reçu ou de cette facture. '
                . 'La date doit être au format AAAA-MM-JJ (ISO 8601), par exemple 2026-10-27. '
                . 'La description doit être rédigée en français et ne doit jamais mentionner ou répéter le nom '
                . 'du commerçant (déjà fourni séparément) — elle doit porter uniquement sur la nature de l\'achat.',
            attachments: [['data' => base64_encode($content), 'mime_type' => $attachment->mimeType]],
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number'],
                    'date' => ['type' => 'string', 'description' => 'Date au format ISO 8601 AAAA-MM-JJ, par exemple 2026-10-27.'],
                    'merchant' => ['type' => 'string'],
                    'description' => [
                        'type' => 'string',
                        'description' => "Description en une phrase, en français, de la nature de l'achat — "
                            . 'sans jamais mentionner le nom du commerçant.',
                    ],
                ],
                'required' => ['amount', 'date', 'merchant', 'description'],
            ]
        );

        try {
            $response = $llmConnector->complete($request);
        } catch (LlmException $e) {
            $this->logFailure($context, $attachmentId, $e->getMessage());
            return;
        }

        $parsed = $response->parsed;
        if ($parsed === null) {
            $this->logFailure($context, $attachmentId, 'Réponse IA non structurée.');
            return;
        }

        $amount = isset($parsed['amount']) && is_numeric($parsed['amount']) ? (float) $parsed['amount'] : null;
        $date = isset($parsed['date']) && is_string($parsed['date']) ? $this->normalizeDate($parsed['date']) : null;
        $merchant = isset($parsed['merchant']) && is_string($parsed['merchant']) && trim($parsed['merchant']) !== ''
            ? mb_substr(trim($parsed['merchant']), 0, 255)
            : null;
        $description = isset($parsed['description']) && is_string($parsed['description']) && trim($parsed['description']) !== ''
            ? mb_substr(trim($parsed['description']), 0, 500)
            : null;
        if ($description !== null && $merchant !== null) {
            $description = $this->stripMerchantName($description, $merchant);
        }

        if ($amount === null && $date === null && $merchant === null && $description === null) {
            $this->logFailure($context, $attachmentId, 'Aucune donnée exploitable dans la réponse IA.');
            return;
        }

        $attachmentRepository->updateSuggestedData($attachmentId, $amount, $date, Attachment::SUGGESTED_SOURCE_AI);
        if ($merchant !== null) {
            $attachmentRepository->updateSuggestedLabel($attachmentId, $merchant);
        }
        if ($description !== null) {
            $attachmentRepository->updateSuggestedDescription($attachmentId, $description);
        }

        $context->journal->log(
            'finance',
            'receipt_extracted',
            'info',
            'Montant/date suggérés automatiquement pour un reçu',
            ['attachment_id' => $attachmentId],
            null
        );

        // Right after parsing is exactly when matching has something new
        // to work with — re-fetch to pick up the suggested_amount/date/
        // label just written above.
        $updatedAttachment = $attachmentRepository->findById($attachmentId);
        if ($updatedAttachment !== null) {
            $matchingService = new ReceiptMatchingService(
                $attachmentRepository,
                new TransactionRepository($pdo, $context->encryption),
                new TransactionAttachmentRepository($pdo),
                $context->journal,
                $llmConnector
            );
            $matchingService->matchReceipt($updatedAttachment);
        }
    }

    /**
     * The prompt asks for AAAA-MM-JJ (ISO 8601), but a model's
     * compliance with a requested string format is never guaranteed —
     * observed in practice returning e.g. "27/10/2026" or an ISO
     * datetime with a time component despite the instruction. Silently
     * discarding anything that isn't exactly "YYYY-MM-DD" threw away a
     * real, usable date the model had actually extracted correctly in
     * most cases. This tolerates an ISO date/datetime and the European
     * DD/MM/YYYY-style format (the convention on the receipts this
     * module reads) — returns null only when nothing recognizable, or
     * not a real calendar date, is found.
     */
    private function normalizeDate(string $rawDate): ?string
    {
        $value = trim($rawDate);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return $this->validDateOrNull((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$#', $value, $m) === 1) {
            return $this->validDateOrNull((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function validDateOrNull(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * The prompt already instructs the model never to mention the
     * merchant name in the description — this is the safety net for
     * when it does anyway (the same lesson as normalizeDate(): a
     * requested constraint on model output is never fully guaranteed).
     * Falls back to the untouched description if removing the merchant
     * name would leave nothing usable behind.
     */
    private function stripMerchantName(string $description, string $merchant): string
    {
        $pattern = '/' . preg_quote($merchant, '/') . '/iu';
        $stripped = preg_replace($pattern, '', $description);
        if ($stripped === null) {
            return $description;
        }

        $stripped = preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped;
        $stripped = trim($stripped, " \t\n\r\0\x0B.,;:-–—");

        return $stripped !== '' ? $stripped : $description;
    }

    private function logFailure(TaskContext $context, int $attachmentId, string $reason): void
    {
        $context->journal->log(
            'finance',
            'receipt_extraction_failed',
            'info',
            "Extraction IA du reçu échouée : {$reason}",
            ['attachment_id' => $attachmentId],
            null
        );
    }
}
