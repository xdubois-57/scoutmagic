<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\File\EncryptedFileStorageService;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Receipt upload/replace/archive and linking to movements. Files
 * themselves are always stored encrypted at rest, via the generic
 * Core\File\EncryptedFileStorageService (same master key as
 * Core\Security\EncryptionService) — this module was the reason that
 * capability got built, per schema.sql's comment on finance_attachments.
 *
 * Every receipt is tied to an account: the underlying file's role_min is
 * set to that account's role_min_view at upload/replace time, so
 * downloading it via Core\File\FileAccessGuard enforces the same floor
 * as the account itself (Controller\ConfigAccountController re-syncs it
 * for existing receipts whenever an account's role_min_view changes).
 */
class ReceiptService
{
    /**
     * PDF and image only — a receipt is always a scan/photo or a PDF
     * export of one, never a general-purpose document; also keeps every
     * receipt renderable as a thumbnail (Core\File\PdfRasterizer for PDF,
     * direct <img> for these two) without a third code path.
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    private const STORAGE_SUBDIRECTORY = 'finance/receipts';

    public function __construct(
        private AttachmentRepository $attachmentRepository,
        private AccountRepository $accountRepository,
        private TransactionAttachmentRepository $transactionAttachmentRepository,
        private EncryptedFileStorageService $fileStorage,
        private TransactionRepository $transactionRepository
    ) {
    }

    /**
     * @throws FinanceException on an unsupported MIME type or an unknown account
     */
    public function upload(
        string $content,
        string $mimeType,
        string $originalFilename,
        int $accountId,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        ?int $uploadedBy
    ): Attachment {
        $account = $this->accountRepository->findById($accountId);
        if ($account === null) {
            throw new FinanceException('Compte introuvable.');
        }
        $this->assertMimeTypeAllowed($mimeType);
        $content = $this->correctOrientation($content, $mimeType);

        $fileId = $this->fileStorage->store(
            $content, $mimeType, $originalFilename, self::STORAGE_SUBDIRECTORY, $account->roleMinView, 'finance', $uploadedBy
        );

        $suggestedSource = ($suggestedAmount !== null || $suggestedDate !== null) ? Attachment::SUGGESTED_SOURCE_MANUAL : null;
        $id = $this->attachmentRepository->create(
            $accountId, $fileId, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate, null, $uploadedBy, $suggestedSource
        );

        $attachment = $this->attachmentRepository->findById($id);
        \assert($attachment !== null);
        return $attachment;
    }

    /**
     * Archives $attachmentId and creates a new attachment chained to it
     * via parent_attachment_id, carrying over the same account and every
     * movement association the old version had (module spec: "transfère
     * les associations").
     *
     * @throws FinanceException when the attachment (or its account) is
     *                           unknown, or the new file's MIME type is unsupported
     */
    public function replace(int $attachmentId, string $content, string $mimeType, string $originalFilename, ?int $uploadedBy): Attachment
    {
        $old = $this->attachmentRepository->findById($attachmentId);
        if ($old === null || $old->accountId === null) {
            throw new FinanceException('Reçu introuvable.');
        }
        $account = $this->accountRepository->findById($old->accountId);
        if ($account === null) {
            throw new FinanceException('Compte introuvable.');
        }
        $this->assertMimeTypeAllowed($mimeType);
        $content = $this->correctOrientation($content, $mimeType);

        $fileId = $this->fileStorage->store(
            $content, $mimeType, $originalFilename, self::STORAGE_SUBDIRECTORY, $account->roleMinView, 'finance', $uploadedBy
        );

        $newId = $this->attachmentRepository->create(
            $old->accountId, $fileId, $mimeType, $originalFilename, null, null, $attachmentId, $uploadedBy
        );

        $this->attachmentRepository->archive($attachmentId);
        $this->transactionAttachmentRepository->transferAttachment($attachmentId, $newId);

        $attachment = $this->attachmentRepository->findById($newId);
        \assert($attachment !== null);
        return $attachment;
    }

    /**
     * Archives the attachment (never physically deleted — module spec)
     * and drops its movement associations. The encrypted file stays on
     * disk.
     *
     * @throws FinanceException when the attachment is unknown
     */
    public function delete(int $attachmentId): void
    {
        if ($this->attachmentRepository->findById($attachmentId) === null) {
            throw new FinanceException('Reçu introuvable.');
        }

        $this->attachmentRepository->archive($attachmentId);
        $this->transactionAttachmentRepository->deleteAllForAttachment($attachmentId);
    }

    /**
     * Links a receipt to one or more movements. Every id is verified to
     * exist AND to belong to the receipt's own account before anything is
     * written: the ids arrive straight from the client (Controller\
     * ReceiptController::associate()'s JSON body), and the caller has only
     * ever been authorized against the *receipt* — never against the
     * movements it is being pointed at. Without this check an intendant
     * could link one of their own receipts to any transaction id in the
     * database and then read that movement's decrypted label/amount/date
     * back out through Controller\ReceiptController::movements(), which
     * also only authorizes against the receipt. The whole call is rejected
     * on the first bad id rather than partially applied.
     *
     * @param int[] $transactionIds
     * @throws FinanceException when the attachment is unknown, or any
     *                           transaction is unknown or belongs to
     *                           another account
     */
    public function associate(int $attachmentId, array $transactionIds): void
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null) {
            throw new FinanceException('Reçu introuvable.');
        }

        $transactionIds = array_map('intval', $transactionIds);
        $accountIdsByTransactionId = $this->transactionRepository->findAccountIdsByIds($transactionIds);

        foreach ($transactionIds as $transactionId) {
            if (!isset($accountIdsByTransactionId[$transactionId])) {
                throw new FinanceException('Mouvement introuvable.');
            }
            if ($accountIdsByTransactionId[$transactionId] !== $attachment->accountId) {
                throw new FinanceException('Ce mouvement appartient à un autre compte que le reçu.');
            }
        }

        foreach ($transactionIds as $transactionId) {
            $this->transactionAttachmentRepository->associate($transactionId, $attachmentId);
        }
    }

    public function dissociate(int $attachmentId, int $transactionId): void
    {
        $this->transactionAttachmentRepository->dissociate($transactionId, $attachmentId);
    }

    /**
     * Active receipts linked to no movement yet, optionally scoped to a
     * single account (the "Finances" pages share one account picker).
     *
     * @return Attachment[]
     */
    public function listPending(?int $accountId = null): array
    {
        // Hash set rather than in_array() over the full id list — this
        // runs once per movements/dashboard page render, and the linear
        // scan made it quadratic in the number of receipts.
        $associatedIds = array_flip($this->transactionAttachmentRepository->findAssociatedAttachmentIds());
        $attachments = $accountId !== null
            ? $this->attachmentRepository->findActiveByAccountId($accountId)
            : $this->attachmentRepository->findActiveOrdered();

        return array_values(array_filter(
            $attachments,
            fn(Attachment $attachment) => !isset($associatedIds[$attachment->id])
        ));
    }

    /**
     * @throws FinanceException on an unsupported MIME type
     */
    private function assertMimeTypeAllowed(string $mimeType): void
    {
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new FinanceException("Type de fichier non autorisé : {$mimeType}.");
        }
    }

    /**
     * Bakes EXIF orientation into the pixels for a phone-photographed
     * receipt, same reason and same rotation mapping as
     * Core\File\UploadHandler::correctOrientation() and the dedicated
     * Core\Photo\*Processor classes — a receipt is explicitly documented
     * above as "always a scan/photo", so it hits this exact phone-camera
     * case, but it's stored via EncryptedFileStorageService rather than
     * UploadHandler::handle(), so it never went through that shared fix.
     * Unlike those classes this only re-encodes when an actual rotation is
     * applied (orientation 3/6/8) — a receipt with no orientation tag, or
     * PNG/PDF, passes through byte-for-byte, since re-encoding a JPEG that
     * needs no fix would cost OCR-relevant quality for nothing.
     */
    private function correctOrientation(string $content, string $mimeType): string
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $content;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($content));
        $orientation = $exif['Orientation'] ?? 1;
        if (!in_array($orientation, [3, 6, 8], true)) {
            return $content;
        }

        // Skip orientation correction (rather than OOM) on a decompression
        // bomb — best-effort, like every other failure branch here; the file
        // is served raw so it is never GD-decoded server-side again (audit M7).
        try {
            \Core\Image\ImageDimensionGuard::assertWithinCeilingFromString($content);
        } catch (\Core\Image\ImageDimensionException) {
            return $content;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return $content;
        }

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
        };
        if ($rotated === false) {
            imagedestroy($image);
            return $content;
        }
        imagedestroy($image);

        ob_start();
        imagejpeg($rotated, null, 92);
        $encoded = (string) ob_get_clean();
        imagedestroy($rotated);

        return $encoded;
    }
}
