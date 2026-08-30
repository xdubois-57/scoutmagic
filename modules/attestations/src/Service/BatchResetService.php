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
use Core\Member\MemberDocumentRepository;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;

/**
 * Takes a whole batch back: the documents it put on members' pages, the
 * certificates it cut, and the batch itself.
 *
 * **This exists because of the one mistake that is only visible
 * afterwards.** A split one page out of step gives every family the next
 * family's certificate, and nothing downstream compares the printed name to
 * the member ever again (§8.86). When somebody does notice, the remedy has
 * to be one frank gesture — not deleting forty documents by hand from forty
 * member sheets, which is how half a wrong batch survives.
 *
 * **All of it, never a part.** The batch line remembers the
 * `member_documents` row it produced, which is exactly what makes this
 * possible: the reset deletes the rows THIS batch created and nothing else,
 * so a member who also holds a certificate from another batch keeps it.
 *
 * **What it cannot undo is the e-mail.** A certificate already delivered is
 * in a mailbox, and nothing here reaches it. That is not a footnote — it is
 * the sentence the confirmation dialog leads with, because a reader who
 * believes the reset recalls the messages will not send the correction that
 * the families actually need.
 *
 * The batch row goes too. A husk with no lines would sit on the deposit
 * page saying nothing useful; what the unit is accountable for — how many
 * went out, and that they were taken back — is in the journal, which this
 * never touches.
 */
class BatchResetService
{
    public function __construct(
        private Connection $connection,
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private MemberDocumentRepository $documents,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journal
    ) {
    }

    /**
     * @return array{documents: int, certificates: int}
     *
     * @throws AttestationsException when the batch is already gone
     */
    public function reset(int $batchId, ?int $actorAccountId): array
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            throw new AttestationsException(
                'Ce lot n\'existe plus. Rechargez la page pour voir la liste à jour.'
            );
        }

        $documentIds = $this->lines->findMemberDocumentIds($batchId);
        // Read BEFORE the rows go: once the lines are deleted there is
        // nothing left pointing at the stored bytes, and they would stay on
        // disk for ever with no row to find them by.
        $fileIds = $this->lines->findFileIds($batchId);

        $pdo = $this->connection->getPdo();
        $pdo->beginTransaction();
        try {
            foreach ($documentIds as $documentId) {
                $this->documents->delete($documentId);
            }
            $this->lines->deleteByBatch($batchId);
            $this->batches->delete($batchId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        // Bytes after the commit, and only after: the rows are what a
        // rollback can restore, the bytes are not (ARCHITECTURE.md §8.3's
        // row-first rule). An interruption here leaves orphaned files,
        // which is invisible and recoverable — the other order leaves a
        // member page offering a download that no longer exists.
        foreach ($fileIds as $fileId) {
            $this->fileStorage->delete($fileId);
        }

        $this->journal->log(
            'attestations',
            'attestation_batch_reset',
            'warning',
            sprintf(
                'Lot %d repris : %d document(s) retiré(s) des pages membres, %d attestation(s) supprimée(s).',
                $batchId,
                count($documentIds),
                count($fileIds)
            ),
            [
                'batch_id' => $batchId,
                'document_count' => count($documentIds),
                'certificate_count' => count($fileIds),
            ],
            $actorAccountId
        );

        return ['documents' => count($documentIds), 'certificates' => count($fileIds)];
    }
}
