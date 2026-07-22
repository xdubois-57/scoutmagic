<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\File\EncryptedFileStorageService;
use Modules\Finance\Repository\Attachment;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;

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
        private EncryptedFileStorageService $fileStorage
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
     * @param int[] $transactionIds
     * @throws FinanceException when the attachment is unknown
     */
    public function associate(int $attachmentId, array $transactionIds): void
    {
        if ($this->attachmentRepository->findById($attachmentId) === null) {
            throw new FinanceException('Reçu introuvable.');
        }

        foreach ($transactionIds as $transactionId) {
            $this->transactionAttachmentRepository->associate((int) $transactionId, $attachmentId);
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
        $associatedIds = $this->transactionAttachmentRepository->findAssociatedAttachmentIds();
        $attachments = $accountId !== null
            ? $this->attachmentRepository->findActiveByAccountId($accountId)
            : $this->attachmentRepository->findActiveOrdered();

        return array_values(array_filter(
            $attachments,
            fn(Attachment $attachment) => !in_array($attachment->id, $associatedIds, true)
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
}
