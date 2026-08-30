<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Config\AppClock;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Member\MemberDocumentRepository;
use Core\Scheduler\SchedulerService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Value\BatchStatus;

/**
 * Publishing a batch, and asking for it to be distributed. Two gestures,
 * and they are deliberately not one.
 *
 * **Publishing puts the documents on the members' pages** — one
 * `member_documents` row per kept line, on `members.id` (the persistent
 * identity: a certificate covers a year that is over and often names
 * somebody who has left) and on the already-cut, already-owner-scoped file.
 * The display is acquired: the private-documents block of the member's page
 * exists already.
 *
 * **Distributing is a separate button, never automatic.** A certificate has
 * a short window of use — a family that does not know theirs is there will
 * ask for it in June, by e-mail, to the treasurer — so the screen says
 * « familles non prévenues » until somebody presses. And the send itself
 * goes through the scheduler rather than the request that asked for it: a
 * batch of two hundred is two hundred SMTP round trips, which is the same
 * reason the mass-mail module never sends inside a request.
 */
class BatchPublicationService
{
    public const TASK_KEY = 'send_batch';

    public function __construct(
        private Connection $connection,
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private BatchVerificationService $verification,
        private MemberDocumentRepository $documents,
        private SchedulerService $scheduler,
        private JournalService $journal
    ) {
    }

    /**
     * Commit the reader's selection and publish what survives it.
     *
     * One gesture on screen, because it is one decision: the lines nobody
     * kept are deleted and the rest become documents. Splitting it in two
     * would give a batch a state between them that means nothing to
     * anybody — split, checked, and still on nobody's page.
     *
     * @param list<int> $selectedLineIds
     *
     * @return array{published: int, discarded: int}
     *
     * @throws AttestationsException when the batch is already published
     */
    public function publish(int $batchId, array $selectedLineIds, ?int $actorAccountId): array
    {
        $discarded = $this->verification->commitSelection($batchId, $selectedLineIds);

        $batch = $this->batches->findById($batchId);
        if ($batch === null || $batch->status !== BatchStatus::Draft) {
            throw new AttestationsException(
                'Ce lot ne peut plus être publié. Rechargez la page pour voir son état actuel.'
            );
        }

        $pdo = $this->connection->getPdo();
        $pdo->beginTransaction();

        try {
            $published = 0;
            foreach ($this->lines->findByBatch($batchId) as $line) {
                if ($line->memberId === null) {
                    continue;
                }

                $documentId = $this->documents->create(
                    $line->memberId,
                    $batch->scoutYearId,
                    $batch->label,
                    $line->fileId,
                    $actorAccountId
                );
                $this->lines->attachDocument($line->id, $documentId);
                $published++;
            }

            $this->batches->markPublished($batchId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        // Counts only (SECURITY.md §11). Which members received one is on
        // their own pages, which is where it belongs.
        $this->journal->log(
            'attestations',
            'attestation_batch_published',
            'info',
            sprintf('Lot %d : %d attestations publiées, %d écartées.', $batchId, $published, $discarded),
            ['batch_id' => $batchId, 'published_count' => $published, 'discarded_count' => $discarded],
            $actorAccountId
        );

        return ['published' => $published, 'discarded' => $discarded];
    }

    /**
     * Ask for the batch to be sent out.
     *
     * The stamp goes on now rather than when the last message leaves: the
     * screen has to stop saying « familles non prévenues » the moment the
     * gesture is made, or somebody presses again and the families get two
     * copies.
     *
     * @throws AttestationsException when the batch is not published, or the
     *                               send has already been asked for
     */
    public function startDistribution(int $batchId, ?int $actorAccountId): void
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null || !$batch->isPublished()) {
            throw new AttestationsException(
                'Ce lot n\'est pas encore publié : il n\'y a rien à envoyer.'
            );
        }

        if ($batch->distributionStartedAt !== null) {
            throw new AttestationsException(
                'L\'envoi a déjà été lancé pour ce lot. Rechargez la page pour voir où il en est.'
            );
        }

        $this->batches->markDistributionStarted($batchId);

        // Now, so the first slice goes out on the next scheduler tick
        // rather than at some future hour nobody chose.
        $this->scheduler->schedule(
            'attestations',
            self::TASK_KEY,
            AppClock::now(),
            ['batch_id' => $batchId],
            (string) $batchId,
            $actorAccountId
        );

        $this->journal->log(
            'attestations',
            'attestation_distribution_started',
            'info',
            sprintf('Lot %d : envoi aux familles demandé.', $batchId),
            ['batch_id' => $batchId],
            $actorAccountId
        );
    }
}
