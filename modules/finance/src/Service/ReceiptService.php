<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\Account;
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
 * Every receipt is tied to an account, and the underlying file carries
 * that link twice, for two different questions:
 *
 *  - `files.role_min` gets the account's `role_min_view` at upload/replace
 *    time, so Core\File\FileAccessGuard enforces the same hierarchical
 *    floor as the account itself (Controller\ConfigAccountController
 *    re-syncs it for existing receipts whenever that value changes);
 *  - `files.owner_type`/`owner_id` name the account itself, which is what
 *    lets the file follow the account's SECTION rule — something a
 *    hierarchical floor cannot express (File\FinanceAccountOwnershipChecker,
 *    ARCHITECTURE.md §8.70).
 *
 * The two are not interchangeable and do not move together: role_min
 * follows the account's setting and is re-synced when it changes, while
 * the owner pair names the account and never moves — no route reassigns a
 * receipt to a different account.
 */
class ReceiptService
{
    /**
     * PDF and image only — a receipt is always a scan/photo or a PDF
     * export of one, never a general-purpose document; also keeps every
     * receipt renderable as a thumbnail (Core\File\PdfRasterizer for PDF,
     * direct <img> for these two) without a third code path.
     */
    public const ALLOWED_MIME_TYPES = [
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
        private TransactionRepository $transactionRepository,
        private ?SettingService $settingService = null,
        /**
         * The AI reading of a receipt, queued from store() so that EVERY
         * way a receipt enters the site gets it.
         *
         * It used to be queued by the three controllers instead, and the
         * fourth way in — a receipt arriving by e-mail, which reaches
         * this class through Service\ExpenseReceiptService and no
         * controller at all — therefore got none: it landed in the
         * sorting pile with no amount, no date, no merchant and no
         * chance of being matched to a movement. The single point every
         * path goes through is the only place that cannot be forgotten
         * by the next one.
         *
         * Null — llm_connector absent — is a no-op inside the service
         * itself; a receipt is always usable with manual entry.
         */
        private ?ReceiptExtractionService $extractionService = null
    ) {
    }

    private const OWNERSHIP_BACKFILLED_SETTING_KEY = 'receipt_file_ownership_backfilled';

    /**
     * Gives every receipt file uploaded before the ownership rule existed
     * the owner pair it would have got today — see
     * Repository\AttachmentRepository::backfillFileOwnership() for what it
     * does and why it is one statement rather than a loop.
     *
     * Called from the composition root's finance block, not from a page,
     * and deliberately so. A backfill hung off the finance configuration
     * screen would leave the hole open on every installation whose
     * superadmin never opens it — and a hole that closes only for units
     * that happen to visit the right page is not closed. Here it closes on
     * the next page load of any kind.
     *
     * The `settings` flag is what makes that affordable: SettingService
     * caches every setting once per request, so on all but the very first
     * run this costs one array lookup and no query at all. Same
     * register-then-setInternal, editable = false shape as
     * FinanceService::ensureDefaultCategories()'s own seed flag.
     *
     * $settingService is optional only so a session-less fixture can build
     * this service without one; a caller that omits it never reaches this
     * method.
     */
    public function ensureReceiptFileOwnership(): void
    {
        if ($this->settingService === null
            || $this->settingService->get(self::OWNERSHIP_BACKFILLED_SETTING_KEY, 'finance', '0') === '1'
        ) {
            return;
        }

        $this->attachmentRepository->backfillFileOwnership(FinanceAccountOwnershipChecker::OWNER_TYPE);

        $this->settingService->register(
            self::OWNERSHIP_BACKFILLED_SETTING_KEY,
            '0',
            'boolean',
            'Justificatifs rattachés à leur compte',
            'Indicateur interne — ne pas modifier.',
            'finance',
            null,
            null,
            false
        );
        $this->settingService->setInternal(self::OWNERSHIP_BACKFILLED_SETTING_KEY, '1', 'finance');
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

        return $this->store($account, $content, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate, $uploadedBy);
    }

    /**
     * A receipt nothing could attribute to an account — the unit's
     * sorting pile.
     *
     * Its one caller is the inbound-mail path: a document of receipt type
     * arrives in a box this module reads, carries no IBAN, and comes from
     * an address that animates no single staff. Losing it would be the
     * worst of the three outcomes — it is somebody's real expense — so it
     * is kept with no account and shown to whoever the unit named as a
     * treasurer (Service\AccountVisibility::isUnassignedReceiptVisibleTo()), who moves it onto
     * an account with reassign().
     *
     * **No account means no `role_min_view` to copy**, so the file gets
     * the sorting pile's own floor. The floor alone would be too
     * permissive — it is a hierarchical one and says nothing about
     * treasurers — which is exactly why the owner pair carries
     * `UNASSIGNED_OWNER_ID` and the checker asks the narrower question
     * (§8.70).
     *
     * Deliberately offers no suggested amount or date: those come from
     * the upload form or from Task\ExtractReceiptDataHandler, and there
     * is no form here.
     */
    public function uploadUnattributed(
        string $content,
        string $mimeType,
        string $originalFilename,
        ?int $uploadedBy
    ): Attachment {
        return $this->store(null, $content, $mimeType, $originalFilename, null, null, $uploadedBy);
    }

    /**
     * The one place a receipt's file and row are written, for an account
     * and for the sorting pile alike — so the two can never drift into
     * storing the same document two different ways.
     */
    private function store(
        ?Account $account,
        string $content,
        string $mimeType,
        string $originalFilename,
        ?float $suggestedAmount,
        ?string $suggestedDate,
        ?int $uploadedBy
    ): Attachment {
        $this->assertMimeTypeAllowed($mimeType);
        $content = $this->correctOrientation($content, $mimeType);

        // role_min stays the floor and is still checked first; the owner
        // pair is what lets the FILE follow the account's section rule,
        // which a hierarchical floor cannot express
        // (File\FinanceAccountOwnershipChecker, ARCHITECTURE.md §8.70).
        // ownerMemberId stays null: a receipt belongs to an account, not
        // to the person who happened to upload it.
        $fileId = $this->fileStorage->store(
            $content,
            $mimeType,
            $originalFilename,
            self::STORAGE_SUBDIRECTORY,
            self::fileRoleMinFor($account),
            'finance',
            $uploadedBy,
            null,
            FinanceAccountOwnershipChecker::OWNER_TYPE,
            self::fileOwnerIdFor($account)
        );

        $suggestedSource = ($suggestedAmount !== null || $suggestedDate !== null) ? Attachment::SUGGESTED_SOURCE_MANUAL : null;
        $id = $this->attachmentRepository->create(
            $account?->id, $fileId, $mimeType, $originalFilename, $suggestedAmount, $suggestedDate, null, $uploadedBy, $suggestedSource
        );

        $attachment = $this->attachmentRepository->findById($id);
        \assert($attachment !== null);

        // Queued here rather than by each caller — see the constructor.
        $this->extractionService?->scheduleExtraction($attachment->id);

        return $attachment;
    }

    /**
     * Move a receipt onto another account — or onto the sorting pile,
     * with a null.
     *
     * **Every movement association goes, and that is not a policy choice
     * this method is free to soften.** associate() guarantees a receipt
     * and its movements share an account; moving the receipt is precisely
     * what would break that guarantee, so the associations that would
     * become cross-account are dropped rather than left behind. The screen
     * says how many before asking — a receipt losing its filing silently
     * would be worse than refusing to move it at all.
     *
     * A move to the account the receipt already sits on is a no-op rather
     * than an error: it changes nothing, and dropping the associations for
     * it would destroy a filing in exchange for nothing.
     *
     * The caller is responsible for having authorized BOTH ends — the
     * receipt where it is now, and the account it is going to. This method
     * checks that the account exists and nothing else: it has no session
     * to ask.
     *
     * @return int the number of movement associations that were dropped
     * @throws FinanceException when the receipt or the target account is unknown
     */
    public function reassign(int $attachmentId, ?int $newAccountId): int
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null) {
            throw new FinanceException('Reçu introuvable.');
        }

        if ($attachment->accountId === $newAccountId) {
            return 0;
        }

        $account = null;
        if ($newAccountId !== null) {
            $account = $this->accountRepository->findById($newAccountId);
            if ($account === null) {
                throw new FinanceException('Compte introuvable.');
            }
        }

        return $this->attachmentRepository->reassign(
            $attachmentId,
            $account?->id,
            $attachment->fileId,
            FinanceAccountOwnershipChecker::OWNER_TYPE,
            self::fileOwnerIdFor($account),
            self::fileRoleMinFor($account)
        );
    }

    /**
     * The hierarchical floor stamped on a receipt's file: its account's
     * own, or the sorting pile's when it has no account.
     */
    private static function fileRoleMinFor(?Account $account): string
    {
        return $account === null ? AccountVisibility::UNASSIGNED_RECEIPT_FLOOR->value : $account->roleMinView;
    }

    private static function fileOwnerIdFor(?Account $account): int
    {
        return $account === null ? FinanceAccountOwnershipChecker::UNASSIGNED_OWNER_ID : $account->id;
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

        // role_min stays the floor and is still checked first; the owner
        // pair is what lets the FILE follow the account's section rule,
        // which a hierarchical floor cannot express
        // (File\FinanceAccountOwnershipChecker, ARCHITECTURE.md §8.70).
        // ownerMemberId stays null: a receipt belongs to an account, not
        // to the person who happened to upload it.
        $fileId = $this->fileStorage->store(
            $content,
            $mimeType,
            $originalFilename,
            self::STORAGE_SUBDIRECTORY,
            $account->roleMinView,
            'finance',
            $uploadedBy,
            null,
            FinanceAccountOwnershipChecker::OWNER_TYPE,
            $account->id
        );

        $newId = $this->attachmentRepository->create(
            $old->accountId, $fileId, $mimeType, $originalFilename, null, null, $attachmentId, $uploadedBy
        );

        $this->attachmentRepository->archive($attachmentId);
        $this->transactionAttachmentRepository->transferAttachment($attachmentId, $newId);

        $attachment = $this->attachmentRepository->findById($newId);
        \assert($attachment !== null);

        // A new file is a new reading — see the constructor for why this
        // is queued here rather than by the caller.
        $this->extractionService?->scheduleExtraction($attachment->id);

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
