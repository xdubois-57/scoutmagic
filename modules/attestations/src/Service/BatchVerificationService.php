<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Value\BatchStatus;

/**
 * The two things a chef d'unité does on the verification screen: resolve a
 * line onto a member, and commit the selection.
 *
 * **Committing deletes what was left aside.** The unchecked lines go, rows
 * and bytes both — no cut kept, no document created, nothing lingering. The
 * batch keeps their COUNT, which is what answers « pourquoi 43 attestations
 * pour 55 membres ? » six months later without keeping a list of who they
 * were.
 */
class BatchVerificationService
{
    public function __construct(
        private Connection $connection,
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private FileRepository $files,
        private EncryptedFileStorageService $fileStorage,
        private JournalService $journal
    ) {
    }

    /**
     * Resolve one line onto one member.
     *
     * The member has to be one the site actually offered: a member id in a
     * request body is a request, never an authority (SECURITY.md §3), and
     * resolving an ambiguity onto somebody who was never a candidate is
     * exactly the wrong-family outcome the ambiguous state exists to
     * prevent.
     *
     * @throws AttestationsException when the line is not in this batch, the
     *                               batch is already published, or the
     *                               member was never a candidate
     */
    public function assignMember(int $batchId, int $lineId, int $memberId): void
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null || $batch->status !== BatchStatus::Draft) {
            throw new AttestationsException(
                'Ce lot ne peut plus être modifié. Rechargez la page pour voir son état actuel.'
            );
        }

        $line = $this->lines->findById($lineId);
        if ($line === null || $line->batchId !== $batchId) {
            throw new AttestationsException(
                'Cette ligne ne fait pas partie de ce lot. Rechargez la page pour voir son état actuel.'
            );
        }

        if (!$this->lines->isCandidate($lineId, $memberId)) {
            throw new AttestationsException(
                'Ce membre n\'était pas proposé pour cette attestation. Rechargez la page et choisissez '
                . 'parmi les membres affichés.'
            );
        }

        $this->lines->assignMember($lineId, $memberId);

        // The certificate becomes readable by its owner and by nobody else
        // the moment it has one — not later, at publication. A file with no
        // owner is reachable by nobody at all, which is the safe direction
        // while a decision is pending.
        $this->files->updateOwnerMember($line->fileId, $memberId);

        $this->journal->log(
            'attestations',
            'attestation_line_assigned',
            'info',
            sprintf('Lot %d : ligne %d rattachée à un membre.', $batchId, $lineId),
            ['batch_id' => $batchId, 'line_id' => $lineId, 'member_id' => $memberId]
        );
    }

    /**
     * Commit the reader's ticks: keep what was checked, delete the rest.
     *
     * @param list<int> $selectedLineIds
     *
     * A line with no member is never among the kept ones, whatever the form
     * said: `applySelection()` refuses to tick one, so an unresolved line is
     * left aside and deleted with the rest. That is deliberate and it is
     * what the screen announces — a certificate with no destination has no
     * page to sit on and nobody to send it to, and the alternative (refusing
     * to validate at all) would leave a chef d'unité stuck on a name Desk
     * simply does not hold.
     *
     * @return int how many lines were discarded
     *
     * @throws AttestationsException when the batch is already published
     */
    public function commitSelection(int $batchId, array $selectedLineIds): int
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null || $batch->status !== BatchStatus::Draft) {
            throw new AttestationsException(
                'Ce lot ne peut plus être modifié. Rechargez la page pour voir son état actuel.'
            );
        }

        // Recording the ticks and dropping what was not ticked are one
        // decision: an interruption between them would leave a batch with
        // everything unticked and nothing deleted, which reads on screen as
        // a reader who unticked the lot.
        $pdo = $this->connection->getPdo();
        $pdo->beginTransaction();

        try {
            $this->lines->applySelection($batchId, $selectedLineIds);
            $discardedFileIds = $this->lines->deleteUnselected($batchId);
            $kept = $this->lines->countByBatch($batchId);
            $this->batches->recordSelection($batchId, $kept, count($discardedFileIds));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        // Rows first, bytes second (ARCHITECTURE.md §8.3), and the bytes
        // only once the rows are committed: an interruption here leaves a
        // stored file nothing points at — invisible and recoverable —
        // rather than a row pointing at bytes that are gone, which is a
        // broken download on a page still claiming the document is there.
        foreach ($discardedFileIds as $fileId) {
            $this->fileStorage->delete($fileId);
        }

        $discarded = count($discardedFileIds);

        $this->journal->log(
            'attestations',
            'attestation_batch_selection_committed',
            'info',
            sprintf('Lot %d : %d attestations retenues, %d écartées.', $batchId, $kept, $discarded),
            ['batch_id' => $batchId, 'kept_count' => $kept, 'discarded_count' => $discarded]
        );

        return $discarded;
    }
}
