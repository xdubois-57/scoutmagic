<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The decision e-mail rows (issue #843, D8): created only for a request
 * holding the decision, never two in play at once, claimed once, and never
 * rewritten once final. The engine-dependent half — rowCount() after an
 * UPDATE, the UNIQUE arbitration, INSERT … SELECT, the cascade — is
 * repeated on MariaDB by DecisionEmailRepositoryOnMysqlTest.
 */
#[Group('database')]
class DecisionEmailRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private DecisionEmailRepository $repository;
    private RegistrationRequestRepository $requests;
    private int $scoutYearId;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->repository = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            null,
            $this->repository
        );
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2026-2027',
            '2026-09-01',
            '2027-08-31'
        );
        $this->now = new \DateTimeImmutable('2026-10-12 10:00:00');
    }

    private function request(string $status, string $firstName = 'Léa'): int
    {
        $id = $this->requests->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        if ($status !== 'pending') {
            $this->requests->updateStatus($id, $status, $status === 'accepted' ? null : $this->now);
        }

        return $id;
    }

    private function insert(int $requestId, string $decision, string $state, ?\DateTimeImmutable $dueAt = null): ?int
    {
        return $this->repository->insert($requestId, $decision, $state, $dueAt, 1, false, $this->now);
    }

    private function email(?int $id): DecisionEmail
    {
        $email = $id !== null ? $this->repository->findById($id) : null;
        $this->assertNotNull($email);

        return $email;
    }

    public function testAnEmailIsCreatedOnlyForARequestHoldingItsDecision(): void
    {
        $pending = $this->request('pending');
        $accepted = $this->request('accepted', 'Noa');
        $encoded = $this->request('encoded', 'Eli');
        $refused = $this->request('refused', 'Ana');

        $this->assertNull($this->insert($pending, 'accepted', 'scheduled'));
        $this->assertNull($this->insert($pending, 'refused', 'scheduled'));
        $this->assertNull($this->insert($refused, 'accepted', 'scheduled'));
        $this->assertNull($this->insert($accepted, 'refused', 'scheduled'));

        $this->assertNotNull($this->insert($accepted, 'accepted', 'scheduled'));
        $this->assertNotNull($this->insert($encoded, 'accepted', 'sending'));
        $this->assertNotNull($this->insert($refused, 'refused', 'sending'));
        $this->assertSame([], $this->repository->findForRequest($pending));
    }

    public function testTheRowRecordsWhatItWasCreatedWith(): void
    {
        $requestId = $this->request('accepted');
        $due = new \DateTimeImmutable('2026-10-13 10:00:00');

        $scheduled = $this->email(
            $this->repository->insert($requestId, 'accepted', 'scheduled', $due, 3, true, $this->now)
        );

        $this->assertSame($requestId, $scheduled->requestId);
        $this->assertSame('accepted', $scheduled->decision);
        $this->assertSame('scheduled', $scheduled->state);
        $this->assertSame('2026-10-13 10:00:00', $scheduled->dueAt?->format('Y-m-d H:i:s'));
        $this->assertSame(3, $scheduled->attempt);
        $this->assertTrue($scheduled->isResend);
        $this->assertNull($scheduled->attemptedAt, 'a planned e-mail has not been attempted');
        $this->assertTrue($scheduled->isCurrent());
        $this->assertSame('2026-10-12 10:00:00', $scheduled->createdAt->format('Y-m-d H:i:s'));
    }

    public function testASendingRowIsAttemptedAtItsCreation(): void
    {
        $sending = $this->email($this->insert($this->request('accepted'), 'accepted', 'sending'));

        $this->assertSame('2026-10-12 10:00:00', $sending->attemptedAt?->format('Y-m-d H:i:s'));
    }

    public function testOnlyOneEmailOfARequestIsInPlayAtATime(): void
    {
        $requestId = $this->request('accepted');
        $first = $this->insert($requestId, 'accepted', 'scheduled');

        $this->assertNull($this->insert($requestId, 'accepted', 'sending'), 'a second e-mail in play is refused');
        $this->assertSame($first, $this->repository->findActiveForRequest($requestId)?->id);

        // Once the first is settled, the request is free again — and
        // another request was never blocked by it.
        $this->assertTrue($this->repository->claim((int) $first, $this->now));
        $this->assertTrue($this->repository->markSent((int) $first, $this->now));
        $this->assertNull($this->repository->findActiveForRequest($requestId));
        $this->assertNotNull($this->insert($requestId, 'accepted', 'sending'));
        $this->assertNotNull($this->insert($this->request('accepted', 'Noa'), 'accepted', 'scheduled'));
    }

    public function testACreationWithAnUnknownStateOrDecisionIsRefused(): void
    {
        $requestId = $this->request('accepted');

        foreach ([['accepted', 'sent'], ['maybe', 'scheduled']] as [$decision, $state]) {
            try {
                $this->insert($requestId, $decision, $state);
                $this->fail("{$decision}/{$state} must be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->repository->findForRequest($requestId));
    }

    public function testAScheduledEmailIsClaimedOnce(): void
    {
        $id = (int) $this->insert($this->request('accepted'), 'accepted', 'scheduled');

        $this->assertTrue($this->repository->claim($id, $this->now));
        $this->assertFalse($this->repository->claim($id, $this->now), 'already claimed');

        $claimed = $this->email($id);
        $this->assertSame('sending', $claimed->state);
        $this->assertSame('2026-10-12 10:00:00', $claimed->attemptedAt?->format('Y-m-d H:i:s'));
    }

    public function testAFinalRowIsNeverRewritten(): void
    {
        $requestId = $this->request('accepted');
        $sent = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->assertTrue($this->repository->markSent($sent, $this->now));
        $failed = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->assertTrue($this->repository->markFailed($failed, 'mail_transport', 'Échec.', $this->now));

        $later = $this->now->modify('+1 hour');
        foreach ([$sent, $failed] as $id) {
            $this->assertFalse($this->repository->claim($id, $later));
            $this->assertFalse($this->repository->markSent($id, $later));
            $this->assertFalse($this->repository->markFailed($id, 'interrupted', 'Autre.', $later));
            $this->assertFalse($this->repository->reschedule($id, $later, $later));
        }

        $this->assertSame('2026-10-12 10:00:00', $this->email($sent)->sentAt?->format('Y-m-d H:i:s'));
        $this->assertSame('sent', $this->email($sent)->state);
        $this->assertSame('failed', $this->email($failed)->state);
        $this->assertSame('Échec.', $this->email($failed)->failureMessage);
        $this->assertSame('mail_transport', $this->email($failed)->failureCode);
    }

    public function testAFailureRecordsItsCodeItsMessageAndItsDate(): void
    {
        $id = (int) $this->insert($this->request('refused'), 'refused', 'sending');

        $this->assertTrue($this->repository->markFailed($id, 'body_not_written', 'Gabarit à rédiger.', $this->now));

        $failed = $this->email($id);
        $this->assertSame('failed', $failed->state);
        $this->assertSame('body_not_written', $failed->failureCode);
        $this->assertSame('Gabarit à rédiger.', $failed->failureMessage);
        $this->assertSame('2026-10-12 10:00:00', $failed->failedAt?->format('Y-m-d H:i:s'));
        $this->assertNull($failed->sentAt);
        $this->assertNull($this->repository->findActiveForRequest($failed->requestId));
    }

    public function testAnUnknownFailureCodeIsRefused(): void
    {
        $id = (int) $this->insert($this->request('refused'), 'refused', 'sending');

        $this->expectException(\InvalidArgumentException::class);
        $this->repository->markFailed($id, 'smtp said no', 'x', $this->now);
    }

    public function testRescheduleMovesOnlyAStillScheduledCurrentEmail(): void
    {
        $requestId = $this->request('accepted');
        $id = (int) $this->insert($requestId, 'accepted', 'scheduled', $this->now->modify('+1 day'));

        $this->assertTrue($this->repository->reschedule($id, $this->now->modify('+2 days'), $this->now));
        $this->assertSame('2026-10-14 10:00:00', $this->email($id)->dueAt?->format('Y-m-d H:i:s'));

        $this->repository->claim($id, $this->now);
        $this->assertFalse($this->repository->reschedule($id, $this->now->modify('+3 days'), $this->now));
        $this->assertSame('2026-10-14 10:00:00', $this->email($id)->dueAt?->format('Y-m-d H:i:s'));
    }

    /**
     * D8: the decision changes. The planned e-mail is cancelled, frees the
     * request and can no longer be claimed; the sent and failed ones keep
     * what happened and are only dated; one in flight keeps its state.
     */
    public function testSupersedingCancelsWhatWasPlannedAndOnlyDatesWhatHappened(): void
    {
        $requestId = $this->request('accepted');
        $sent = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markSent($sent, $this->now);
        $failed = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markFailed($failed, 'mail_transport', 'Échec.', $this->now);
        $scheduled = (int) $this->insert($requestId, 'accepted', 'scheduled', $this->now->modify('+1 day'));
        $other = (int) $this->insert($this->request('accepted', 'Noa'), 'accepted', 'scheduled');

        $later = $this->now->modify('+1 hour');
        $this->assertSame(1, $this->repository->supersedeForRequest($requestId, $later));

        $cancelled = $this->email($scheduled);
        $this->assertSame('cancelled', $cancelled->state);
        $this->assertSame('decision_changed', $cancelled->cancelReason);
        $this->assertSame('2026-10-12 11:00:00', $cancelled->cancelledAt?->format('Y-m-d H:i:s'));
        $this->assertFalse($this->repository->claim($scheduled, $later), 'a cancelled e-mail never leaves');
        $this->assertNull($this->repository->findActiveForRequest($requestId));

        $this->assertSame('sent', $this->email($sent)->state);
        $this->assertSame('failed', $this->email($failed)->state);
        foreach ([$sent, $failed, $scheduled] as $id) {
            $this->assertSame('2026-10-12 11:00:00', $this->email($id)->supersededAt?->format('Y-m-d H:i:s'));
            $this->assertFalse($this->email($id)->isCurrent());
        }
        $this->assertSame('scheduled', $this->email($other)->state, 'another request is untouched');

        // A second change dates nothing again.
        $this->assertSame(0, $this->repository->supersedeForRequest($requestId, $later->modify('+1 day')));
        $this->assertSame('2026-10-12 11:00:00', $this->email($sent)->supersededAt?->format('Y-m-d H:i:s'));
    }

    public function testAnEmailInFlightWhenTheDecisionChangesKeepsItsStateButNoLongerCounts(): void
    {
        $requestId = $this->request('accepted');
        $sending = (int) $this->insert($requestId, 'accepted', 'sending');

        $this->assertSame(0, $this->repository->supersedeForRequest($requestId, $this->now));
        $this->assertSame('sending', $this->email($sending)->state);

        // It finishes after the change: sent, but it describes the old
        // decision, so it makes nothing visible.
        $this->assertTrue($this->repository->markSent($sending, $this->now));
        $this->assertSame([], $this->repository->firstSentAtByRequest([$requestId]));
    }

    public function testTheFirstSendOfTheCurrentDecisionIsTheDateRead(): void
    {
        $requestId = $this->request('accepted');
        $old = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markSent($old, new \DateTimeImmutable('2026-09-01 08:00:00'));
        $this->repository->supersedeForRequest($requestId, $this->now);

        $first = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markSent($first, new \DateTimeImmutable('2026-10-01 08:00:00'));
        $resend = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markSent($resend, new \DateTimeImmutable('2026-10-05 08:00:00'));
        $failed = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markFailed($failed, 'mail_transport', 'Échec.', $this->now);
        $untouched = $this->request('accepted', 'Noa');

        $dates = $this->repository->firstSentAtByRequest([$requestId, $untouched, $requestId]);

        $this->assertSame(['accepted'], array_keys($dates[$requestId]));
        $this->assertSame('2026-10-01 08:00:00', $dates[$requestId]['accepted']->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey($untouched, $dates);
        $this->assertSame([], $this->repository->firstSentAtByRequest([]));
    }

    public function testDueEmailsAreTheScheduledOnesWhoseDateHasComeOldestFirst(): void
    {
        $scheduled = fn(string $name, ?string $shift, string $decision = 'accepted'): int => (int) $this->insert(
            $this->request($decision, $name),
            $decision,
            'scheduled',
            $shift !== null ? $this->now->modify($shift) : null
        );
        $late = $scheduled('Ana', '-1 hour');
        $later = $scheduled('Eli', '-2 hours');
        $exact = $scheduled('Noa', '+0 seconds', 'refused');
        $scheduled('Zoé', '+1 minute');
        $scheduled('Tom', null);
        $claimed = $scheduled('Max', '-3 hours');
        $this->repository->claim($claimed, $this->now);

        $due = $this->repository->findDue($this->now, 10);

        $this->assertSame([$later, $late, $exact], array_map(static fn(DecisionEmail $email) => $email->id, $due));
        $this->assertCount(2, $this->repository->findDue($this->now, 2));
    }

    public function testAStaleClaimIsRecordedAsInterruptedAndARecentOneIsLeftAlone(): void
    {
        $staleRequest = $this->request('accepted');
        $stale = (int) $this->repository->insert(
            $staleRequest,
            'accepted',
            'sending',
            null,
            1,
            false,
            $this->now->modify('-11 minutes')
        );
        $recentRequest = $this->request('accepted', 'Noa');
        $recent = (int) $this->repository->insert(
            $recentRequest,
            'accepted',
            'sending',
            null,
            1,
            false,
            $this->now->modify('-9 minutes')
        );

        $cutoff = $this->now->modify('-10 minutes');
        $this->assertSame(1, $this->repository->failStaleSending($staleRequest, $cutoff, 'Interrompu.', $this->now));
        $this->assertSame(0, $this->repository->failStaleSending($recentRequest, $cutoff, 'Interrompu.', $this->now));

        $this->assertSame('failed', $this->email($stale)->state);
        $this->assertSame('interrupted', $this->email($stale)->failureCode);
        $this->assertSame('Interrompu.', $this->email($stale)->failureMessage);
        $this->assertNull($this->repository->findActiveForRequest($staleRequest));
        $this->assertSame('sending', $this->email($recent)->state);
    }

    /**
     * A send merely slow enough to be taken for interrupted, which then
     * reports back: the unknown outcome becomes what happened. Only an
     * interruption is resolved that way — a real failure stays one.
     */
    public function testALateSuccessResolvesAnInterruptionAndNothingElse(): void
    {
        $requestId = $this->request('accepted');
        $slow = (int) $this->repository->insert(
            $requestId,
            'accepted',
            'sending',
            null,
            1,
            false,
            $this->now->modify('-20 minutes')
        );
        $this->repository->failStaleSending($requestId, $this->now->modify('-10 minutes'), 'Interrompu.', $this->now);

        $this->assertTrue($this->repository->markSent($slow, $this->now));

        $resolved = $this->email($slow);
        $this->assertSame('sent', $resolved->state);
        $this->assertSame('2026-10-12 10:00:00', $resolved->sentAt?->format('Y-m-d H:i:s'));
        $this->assertNull($resolved->failureCode);
        $this->assertNull($resolved->failedAt);
    }

    public function testSupersedingCanSpareTheDecisionTheRequestStillHolds(): void
    {
        $requestId = $this->request('refused');
        $refusal = (int) $this->insert($requestId, 'refused', 'scheduled', $this->now->modify('+1 day'));

        $this->assertSame(0, $this->repository->supersedeForRequest($requestId, $this->now, 'refused'));
        $this->assertSame('scheduled', $this->email($refusal)->state);
        $this->assertTrue($this->email($refusal)->isCurrent());

        $this->assertSame(1, $this->repository->supersedeForRequest($requestId, $this->now, 'accepted'));
        $this->assertSame('cancelled', $this->email($refusal)->state);
    }

    public function testTheHistoryIsEveryRowOldestFirst(): void
    {
        $requestId = $this->request('accepted');
        $first = (int) $this->insert($requestId, 'accepted', 'sending');
        $this->repository->markFailed($first, 'mail_transport', 'Échec.', $this->now);
        $this->repository->supersedeForRequest($requestId, $this->now);
        $second = (int) $this->insert($requestId, 'accepted', 'scheduled');

        $this->assertSame(
            [$first, $second],
            array_map(static fn(DecisionEmail $email) => $email->id, $this->repository->findForRequest($requestId))
        );
    }

    public function testDeletingARequestsEmailsLeavesTheOthers(): void
    {
        $gone = $this->request('accepted');
        $kept = $this->request('accepted', 'Noa');
        $this->insert($gone, 'accepted', 'scheduled');
        $this->insert($kept, 'accepted', 'scheduled');

        $this->repository->deleteForRequest($gone);

        $this->assertSame([], $this->repository->findForRequest($gone));
        $this->assertCount(1, $this->repository->findForRequest($kept));
    }

    public function testTheDecisionOfAStatus(): void
    {
        $this->assertSame('accepted', DecisionEmail::decisionForStatus('accepted'));
        $this->assertSame('accepted', DecisionEmail::decisionForStatus('encoded'));
        $this->assertSame('refused', DecisionEmail::decisionForStatus('refused'));
        $this->assertNull(DecisionEmail::decisionForStatus('pending'));
        $this->assertNull(DecisionEmail::decisionForStatus('withdrawn'));
        $this->assertSame(['accepted', 'encoded'], DecisionEmail::statusesFor('accepted'));
        $this->assertSame(['refused'], DecisionEmail::statusesFor('refused'));
        $this->assertSame([], DecisionEmail::statusesFor('withdrawn'));
    }
}
