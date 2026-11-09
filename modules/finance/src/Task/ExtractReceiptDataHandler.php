<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Task;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\PdfRasterizer;
use Core\File\PdfTextExtractor;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceiptDateNormalizer;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\Finance\Service\ReceiptService;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

/**
 * One-shot task (scheduled by Service\ReceiptExtractionService right
 * after an upload) — reads the receipt's amount/date/merchant/
 * description, then writes suggested_amount/suggested_date/
 * suggested_label/suggested_description back onto the attachment.
 * Attempted extraction failures (API error, unparseable response) are
 * journaled and absorbed. Missing providers or tiers skip extraction;
 * a receipt always remains usable manually.
 *
 * A PDF is handled specially (buildRequestForPdf()): its embedded text
 * layer, when it has one, is extracted (Core\File\PdfTextExtractor) and
 * sent as plain text on the CHEAP tier — exact (no hallucination risk)
 * and provider-agnostic, unlike sending the file itself as an
 * attachment, which not every provider's chat API actually supports
 * (confirmed in practice: a provider silently receiving no usable
 * content still had to invent an answer to satisfy the response
 * schema). A PDF with no text layer (a scanned/photographed receipt
 * saved as PDF) falls back to rendering its first page as a JPEG
 * (Core\File\PdfRasterizer) and going through the same OCR-tier
 * image path as a real photo upload. Every other supported type
 * (image/*) goes straight through that image path — this module never
 * names a model or provider. Images require an available OCR tier;
 * a text-only model never establishes image support. Missing tiers
 * skip extraction without marking a document unreadable, while
 * matching can still use the receipt's existing metadata.
 */
class ExtractReceiptDataHandler implements TaskHandlerInterface
{
    private const PROMPT = 'Extrait le montant total, la date, le nom du commerçant, et une description en une phrase '
        . "de l'objet de cet achat (par exemple « Achat de fournitures de bureau » ou « Cotisation "
        . 'trimestrielle »). '
        . 'La date doit être au format AAAA-MM-JJ (ISO 8601), par exemple 2026-10-27. '
        . 'La description doit être rédigée en français et ne doit jamais mentionner ou répéter le nom '
        . 'du commerçant (déjà fourni séparément) — elle doit porter uniquement sur la nature de l\'achat.';

    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'amount' => ['type' => 'number'],
            'date' => ['type' => 'string', 'description' => 'Date au format ISO 8601 AAAA-MM-JJ, par exemple '
                . '2026-10-27.'],
            'merchant' => ['type' => 'string'],
            'description' => [
                'type' => 'string',
                'description' => "Description en une phrase, en français, de la nature de l'achat — "
                    . 'sans jamais mentionner le nom du commerçant.',
            ],
        ],
        'required' => ['amount', 'date', 'merchant', 'description'],
    ];

    /**
     * Cap on the text handed to the LLM — receipts are short; this only
     * guards against a stray multi-page PDF blowing up the prompt.
     */
    private const MAX_EXTRACTED_TEXT_CHARS = 8000;

    public function __construct(private ?PdfRasterizer $rasterizer = null)
    {
    }

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
        $attachmentRepository = new AttachmentRepository($pdo, $context->encryption);

        $attachment = $attachmentRepository->findById($attachmentId);
        if ($attachment === null || $attachment->status !== Attachment::STATUS_ACTIVE) {
            return;
        }

        // The connector is a capability (ARCHITECTURE.md §7.5 on the
        // scheduled path): null — llm_connector absent or disabled —
        // means no suggestion is written and the receipt stays fully
        // usable manually, same degradation as no configured provider.
        $llmConnector = $context->getOptional(LlmConnectorInterface::class);
        if ($llmConnector === null || !$llmConnector->isAvailable()) {
            return;
        }

        $fileStorage = new EncryptedFileStorageService(
            new FileRepository($pdo),
            $context->encryption,
            $context->storagePath
        );

        $ocrAvailable = $llmConnector->isTierAvailable(LlmTier::OCR);
        $request = null;
        if ($attachment->mimeType === 'application/pdf' || $ocrAvailable) {
            try {
                $content = $fileStorage->retrieve($attachment->fileId);
            } catch (\RuntimeException $e) {
                $this->logFailure($context, $attachmentId, $e->getMessage());
                return;
            }

            $request = $attachment->mimeType === 'application/pdf'
                ? $this->buildRequestForPdf($content, $attachment->originalFilename, $ocrAvailable)
                : $this->buildImageRequest($content, $attachment->mimeType, $attachment->originalFilename);
        }

        $parsed = null;
        if ($request === null) {
            if ($ocrAvailable) {
                $this->logFailure(
                    $context,
                    $attachmentId,
                    "PDF sans texte exploitable et impossible à convertir en image pour l'analyse."
                );
            }
        } elseif ($llmConnector->isTierAvailable($request->tier)) {
            try {
                $parsed = $llmConnector->complete($request)->parsed;
                if ($parsed === null) {
                    $this->logFailure($context, $attachmentId, 'Réponse IA non structurée.');
                }
            } catch (LlmException $e) {
                $this->logFailure($context, $attachmentId, $e->getMessage());
            }
        }

        $amount = isset($parsed['amount']) && is_numeric($parsed['amount']) ? (float) $parsed['amount'] : null;
        $date = isset($parsed['date']) && is_string($parsed['date'])
            ? ReceiptDateNormalizer::normalize($parsed['date'])
            : null;
        $merchant = isset($parsed['merchant']) && is_string($parsed['merchant']) && trim($parsed['merchant']) !== ''
            ? mb_substr(trim($parsed['merchant']), 0, 255)
            : null;
        $description = isset($parsed['description'])
            && is_string($parsed['description'])
            && trim($parsed['description']) !== ''
            ? mb_substr(trim($parsed['description']), 0, 500)
            : null;
        if ($description !== null && $merchant !== null) {
            $description = $this->stripMerchantName($description, $merchant);
        }

        if ($amount === null && $date === null && $merchant === null && $description === null) {
            if ($parsed !== null) {
                // $request/complete() succeeded but yielded nothing
                // exploitable — the two other failure branches above
                // already logged their own reason.
                $this->logFailure($context, $attachmentId, 'Aucune donnée exploitable dans la réponse IA.');
            }
        } else {
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
        }

        // Attempted regardless of whether extraction itself succeeded
        // (PDF or image alike — a PDF with no usable text/image, or an
        // unreadable photo, still deserves a matching attempt and the
        // "IA : aucun mouvement trouvé" indicator once it's tried,
        // exactly like a receipt whose extraction did succeed but whose
        // amount matches nothing — ReceiptMatchingService tolerates a
        // receipt with no known amount/date/merchant just fine, the AI
        // match prompt sends "inconnu" in their place). Only re-fetched
        // here to pick up whatever updateSuggestedData() above just wrote.
        $updatedAttachment = $attachmentRepository->findById($attachmentId);
        if ($updatedAttachment !== null) {
            $transactionRepository = new TransactionRepository($pdo, $context->encryption);
            $transactionAttachmentRepository = new TransactionAttachmentRepository($pdo);
            $accountRepository = new AccountRepository($pdo, $context->encryption);

            $matchingService = new ReceiptMatchingService(
                $attachmentRepository,
                $transactionRepository,
                $transactionAttachmentRepository,
                $context->journal,
                $llmConnector,
                // What files a sorting-pile receipt onto the account of
                // the movement it turned out to belong to. Without it a
                // receipt that arrived by e-mail and could not be placed
                // stays unplaced for ever, however exactly its amount and
                // date answer a movement two accounts over.
                new ReceiptService(
                    $attachmentRepository,
                    $accountRepository,
                    $transactionAttachmentRepository,
                    $fileStorage,
                    $transactionRepository
                )
            );
            $matchingService->matchReceipt($updatedAttachment);
        }
    }

    /**
     * Text-first, image-fallback (see class doc comment). Returns null when
     * the PDF has no usable text layer and OCR is unavailable or
     * rasterization fails. The caller separately checks a built request's tier.
     */
    private function buildRequestForPdf(string $content, string $originalFilename, bool $ocrAvailable): ?LlmRequest
    {
        $text = (new PdfTextExtractor())->extractText($content);
        if ($text !== null) {
            return new LlmRequest(
                tier: LlmTier::CHEAP,
                prompt: self::PROMPT . $this->filenameHint($originalFilename)
                    . "\n\nVoici le texte extrait du document :\n\n" . mb_substr(
                        $text,
                        0,
                        self::MAX_EXTRACTED_TEXT_CHARS
                    ),
                responseSchema: self::RESPONSE_SCHEMA
            );
        }

        if (!$ocrAvailable) {
            return null;
        }
        $image = ($this->rasterizer ?? new PdfRasterizer())->firstPageToJpeg($content);
        if ($image === null) {
            return null;
        }

        return $this->buildImageRequest($image, 'image/jpeg', $originalFilename);
    }

    private function buildImageRequest(string $content, string $mimeType, string $originalFilename): LlmRequest
    {
        return new LlmRequest(
            tier: LlmTier::OCR,
            prompt: self::PROMPT . $this->filenameHint($originalFilename),
            attachments: [['data' => base64_encode($content), 'mime_type' => $mimeType]],
            responseSchema: self::RESPONSE_SCHEMA
        );
    }

    /**
     * The uploader's original filename sometimes carries information the
     * document body itself doesn't state explicitly (a merchant name, a
     * purchase category, a date) — worth passing along as extra context,
     * never as a substitute for what's actually written on the receipt.
     */
    private function filenameHint(string $originalFilename): string
    {
        return "\n\nNom du fichier envoyé par l'utilisateur (peut contenir des indices utiles, ex. nom du magasin) : "
            . $originalFilename;
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
