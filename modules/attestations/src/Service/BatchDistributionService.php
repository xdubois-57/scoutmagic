<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Config\AppClock;
use Core\Journal\JournalService;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberDocumentMailer;
use Core\Notification\NotificationService;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Value\DeliveryState;

/**
 * Sends a published batch out, one slice at a time.
 *
 * The slice is the whole design: a batch of two hundred is two hundred SMTP
 * round trips, and doing them in one burst is what gets a domain's
 * deliverability reputation ruined — the same reason the mass-mail module
 * sends in batches through the scheduler rather than inside a request.
 *
 * **A send is never retried.** A transport failure cannot tell « never
 * left » from « left, and then the connection dropped », and a certificate
 * delivered twice is worse than one delivered once: the family cannot know
 * which is which. The line is marked `failed` and the screen counts it; the
 * certificate itself is on the member's page either way, which is where a
 * family with an account still finds it.
 */
class BatchDistributionService
{
    /**
     * How many messages one scheduler slice sends. Small enough that a
     * slice fits comfortably inside the scheduler's own time budget
     * (`scheduler_slice_seconds`, 75 by default) on a shared host where an
     * SMTP round trip is not fast, and large enough that a unit of two
     * hundred drains in a handful of ticks.
     */
    public const SLICE_SIZE = 20;

    public const NOTIFICATION_TYPE = 'attestations.published';

    public function __construct(
        private BatchRepository $batches,
        private BatchLineRepository $lines,
        private MemberNameRepository $members,
        private MemberDocumentMailer $mailer,
        private MemberAccountResolver $accounts,
        private JournalService $journal,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Send one slice.
     *
     * @return bool whether work remains — the caller re-arms itself on true
     */
    public function sendSlice(int $batchId, string $unitName): bool
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null || !$batch->isPublished() || $batch->notifiedAt !== null) {
            return false;
        }

        $pending = $this->lines->findPendingDelivery($batchId, self::SLICE_SIZE);

        if ($pending === []) {
            $this->finish($batchId, $unitName);

            return false;
        }

        $memberIds = [];
        foreach ($pending as $line) {
            if ($line->memberId !== null) {
                $memberIds[] = $line->memberId;
            }
        }
        $addresses = $this->members->findMostRecentEmails($memberIds);

        $sent = 0;
        $failed = 0;
        $unreachable = 0;

        foreach ($pending as $line) {
            $address = $line->memberId !== null ? ($addresses[$line->memberId] ?? null) : null;

            if ($address === null) {
                // The site holds no address for this member, in any year.
                // Settled rather than left pending: a line nothing can be
                // done about must not make the slice loop forever.
                $this->lines->recordDelivery($line->id, DeliveryState::NoAddress, null);
                $unreachable++;
                continue;
            }

            try {
                $this->mailer->send($batch->label, $line->fileId, $address, $unitName);
                $this->lines->recordDelivery($line->id, DeliveryState::Sent, AppClock::now()->format('Y-m-d H:i:s'));
                $sent++;
            } catch (\Throwable $e) {
                // Never retried, and the reason is above. The exception's
                // own text is a library's and may name the recipient, so
                // only the line id is recorded (SECURITY.md §11).
                $this->lines->recordDelivery($line->id, DeliveryState::Failed, null);
                $failed++;
            }
        }

        $this->journal->log(
            'attestations',
            'attestation_slice_sent',
            $failed > 0 ? 'warning' : 'info',
            sprintf(
                'Lot %d : %d envoyée(s), %d sans adresse, %d refusée(s).',
                $batchId,
                $sent,
                $unreachable,
                $failed
            ),
            [
                'batch_id' => $batchId,
                'sent_count' => $sent,
                'no_address_count' => $unreachable,
                'failed_count' => $failed,
            ]
        );

        return true;
    }

    /**
     * The last slice is done: tell the families who still have an account,
     * once each.
     *
     * **One notification per account, never one per document.** A parent of
     * three children would otherwise get three in a row, which is how people
     * learn to swipe this kind of message away without reading it. The
     * recipients are resolved at this moment rather than at publication:
     * somebody who lost their access in between simply is not in the list,
     * with nothing to invalidate.
     */
    private function finish(int $batchId, string $unitName): void
    {
        $batch = $this->batches->findById($batchId);
        if ($batch === null) {
            return;
        }

        $recipients = [];
        foreach ($this->lines->findMemberIds($batchId) as $memberId) {
            foreach ($this->accounts->accountIdsForMember($memberId) as $accountId) {
                // Keyed by account: one message per login, whatever the
                // number of children behind it.
                $recipients[$accountId] = ['userAccountId' => $accountId, 'memberId' => $memberId];
            }
        }

        if ($recipients !== [] && $this->notifications !== null) {
            $this->notifications->dispatch(
                self::NOTIFICATION_TYPE,
                array_values($recipients),
                [
                    'title' => 'Un document vous attend',
                    // The batch's own label and nothing about anybody: a
                    // push lands on a lock screen, readable by whoever is
                    // holding the phone (SECURITY.md §19).
                    'body' => $batch->label,
                    'url' => '/',
                ]
            );
        }

        $this->batches->markNotified($batchId);

        $counts = $this->lines->countByDeliveryState($batchId);
        $this->journal->log(
            'attestations',
            'attestation_distribution_finished',
            ($counts[DeliveryState::Failed->value] ?? 0) > 0 ? 'warning' : 'info',
            sprintf(
                'Lot %d : envoi terminé, %d notification(s) de compte.',
                $batchId,
                count($recipients)
            ),
            ['batch_id' => $batchId, 'account_count' => count($recipients)] + $counts
        );
    }
}
