<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\Journal\JournalService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Value\AttestationCategory;

/**
 * Turns a deposited PDF into a batch: read it, cut it, store each piece,
 * write the lines.
 *
 * **The cut happens here, at deposit, not at publication.** That is what
 * makes the verification screen a decision about documents that exist
 * rather than a promise about documents that do not — and it is what lets
 * the deposited file go immediately, which is the point: it holds every
 * family's certificate in one document and has no use once the pieces are
 * out of it.
 *
 * Every piece is stored through `Core\File\EncryptedFileStorageService`,
 * never as a plain file: these are the most nominative documents this site
 * holds. `owner_member_id` is set at the same moment for a line that has a
 * member, so the file is owner-scoped from the instant it exists rather
 * than from the instant somebody publishes it. A line resolved later by
 * hand gets its owner then (see BatchVerificationService).
 */
class BatchDepositService
{
    /** Relative to the storage root; `storage/**` is gitignored wholesale. */
    public const STORAGE_SUBDIRECTORY = 'attestations/documents';

    /**
     * The floor on the split certificate's own `files` row. It is only a
     * floor: `owner_member_id` is what actually decides, and it is checked
     * on top of this (ARCHITECTURE.md §8.3). A file with no member yet —
     * an unresolved line — is therefore reachable by nobody below admin
     * AND by nobody at all through owner scoping, which is the safe
     * direction while a decision is pending.
     */
    public const FILE_ROLE_MIN = 'admin';

    public function __construct(
        private Connection $connection,
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private MemberNameRepository $members,
        private AttestationPdfReader $reader,
        private AttestationPdfSplitter $splitter,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journal
    ) {
    }

    /**
     * @param string $pdfPath a readable path to the deposited file; the
     *                        caller owns it and deletes it afterwards,
     *                        success or failure alike
     *
     * @return int the new batch's id
     *
     * @throws PageCountMismatchException when the arithmetic does not fall right
     * @throws AttestationsException      on anything else the reader or the cut refuses
     */
    public function deposit(
        string $pdfPath,
        int $scoutYearId,
        AttestationCategory $category,
        string $label,
        ?int $createdBy
    ): int {
        $analysis = $this->reader->analyze($pdfPath, $this->members->buildDirectory());

        $pdo = $this->connection->getPdo();
        $storedFileIds = [];

        try {
            // The cutting and the storing happen OUTSIDE the transaction,
            // deliberately. `EncryptedFileStorageService::store()` writes
            // bytes to disk and a `files` row together, and a filesystem
            // does not roll back: doing this inside the transaction would
            // make a rollback take away the row that the cleanup needs in
            // order to find the bytes, leaving a certificate on disk that
            // nothing points at. It also keeps a three-hundred-page cut
            // from holding a write transaction open for its duration.
            foreach ($analysis->attestations as $index => $attestation) {
                $storedFileIds[] = $this->fileStorage->store(
                    $this->splitter->extract($pdfPath, $attestation->firstPage, $attestation->lastPage),
                    'application/pdf',
                    $this->fileNameFor($label, $index + 1),
                    self::STORAGE_SUBDIRECTORY,
                    self::FILE_ROLE_MIN,
                    'attestations',
                    $createdBy,
                    $attestation->matchedMemberId()
                );
            }

            $pdo->beginTransaction();

            $batchId = $this->batches->create(
                $scoutYearId,
                $category,
                $label,
                $analysis->pageCount,
                $analysis->pagesPerDocument,
                count($analysis->attestations),
                $createdBy
            );

            foreach ($analysis->attestations as $index => $attestation) {
                $memberId = $attestation->matchedMemberId();

                $this->lines->create(
                    $batchId,
                    $index + 1,
                    $attestation->firstPage,
                    $attestation->lastPage,
                    $attestation->readName,
                    $memberId,
                    $attestation->state(),
                    $storedFileIds[$index],
                    $memberId === null ? $attestation->memberIds : []
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // The batch and its lines are gone with the transaction; the
            // certificates are not, and left behind they would be
            // nominative documents nothing points at. Their `files` rows
            // were committed outside the transaction precisely so this
            // still finds them.
            $this->discardStoredFiles($storedFileIds);

            throw $e;
        }

        // Counts only. Naming who was matched would put the unit's roster
        // in the journal, which SECURITY.md §11 forbids outright.
        $this->journal->log(
            'attestations',
            'attestation_batch_created',
            'info',
            sprintf(
                'Lot %d : %d attestations lues sur %d pages, %d à traiter.',
                $batchId,
                count($analysis->attestations),
                $analysis->pageCount,
                $analysis->pendingCount()
            ),
            [
                'batch_id' => $batchId,
                'scout_year_id' => $scoutYearId,
                'category' => $category->value,
                'page_count' => $analysis->pageCount,
                'pages_per_document' => $analysis->pagesPerDocument,
                'document_count' => count($analysis->attestations),
                'pending_count' => $analysis->pendingCount(),
            ]
        );

        return $batchId;
    }

    /**
     * The name the family will see when they download it. It carries the
     * batch's own label and nothing about anybody: a file name travels
     * through a downloads folder, an e-mail client and a backup, and the
     * one this module controls has no reason to carry a person's name that
     * the document's own first page already carries.
     */
    private function fileNameFor(string $label, int $position): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label));
        $slug = trim($slug, '-');

        return ($slug === '' ? 'attestation' : $slug) . '-' . $position . '.pdf';
    }

    /**
     * @param list<int> $fileIds
     */
    private function discardStoredFiles(array $fileIds): void
    {
        foreach ($fileIds as $fileId) {
            try {
                $this->fileStorage->delete($fileId);
            } catch (\Throwable) {
                // A file that cannot be deleted must not mask the failure
                // that brought us here — the caller is already throwing,
                // and an orphan on disk is the lesser of the two.
            }
        }
    }
}
