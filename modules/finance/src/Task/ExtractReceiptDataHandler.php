<?php

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
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
 * the receipt's amount/date/merchant, then writes suggested_amount/
 * suggested_date/suggested_label back onto the attachment. Any failure
 * (no provider, API error, unparseable response) is journaled and
 * otherwise silently absorbed — a failed extraction never blocks the
 * receipt from being used manually.
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
            prompt: 'Extrait le montant total, la date, et le nom du commerçant de ce reçu ou de cette facture.',
            attachments: [['data' => base64_encode($content), 'mime_type' => $attachment->mimeType]],
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'number'],
                    'date' => ['type' => 'string'],
                    'merchant' => ['type' => 'string'],
                ],
                'required' => ['amount', 'date', 'merchant'],
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
        $date = isset($parsed['date']) && is_string($parsed['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed['date']) === 1
            ? $parsed['date']
            : null;
        $merchant = isset($parsed['merchant']) && is_string($parsed['merchant']) && trim($parsed['merchant']) !== ''
            ? mb_substr(trim($parsed['merchant']), 0, 255)
            : null;

        if ($amount === null && $date === null && $merchant === null) {
            $this->logFailure($context, $attachmentId, 'Aucune donnée exploitable dans la réponse IA.');
            return;
        }

        $attachmentRepository->updateSuggestedData($attachmentId, $amount, $date, Attachment::SUGGESTED_SOURCE_AI);
        if ($merchant !== null) {
            $attachmentRepository->updateSuggestedLabel($attachmentId, $merchant);
        }

        $context->journal->log(
            'finance',
            'receipt_extracted',
            'info',
            'Montant/date suggérés automatiquement pour un reçu',
            ['attachment_id' => $attachmentId],
            null
        );
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
