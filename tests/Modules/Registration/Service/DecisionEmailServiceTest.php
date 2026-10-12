<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The life of a decision e-mail (issue #843, D8): planned, rescheduled,
 * attempted, sent, failed, retried, resent — and cancelled by a change of
 * decision, whichever path changes it. The sending itself is
 * RequestEmailServiceTest's.
 */
#[Group('database')]
class DecisionEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private DecisionEmailRepository $repository;
    private RegistrationRequestRepository $requests;
    private DecisionEmailService $service;
    private RequestStatusService $statusService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->repository = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            null,
            $this->repository
        );
        $this->service = new DecisionEmailService($this->repository, $journal);
        $this->statusService = new RequestStatusService($this->requests, $journal);
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2026-2027',
            '2026-09-01',
            '2027-08-31'
        );
    }

    private function request(string $status = 'pending', string $firstName = 'Léa'): RegistrationRequest
    {
        $id = $this->requests->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        if ($status !== 'pending') {
            $this->requests->updateStatus($id, $status, $status === 'accepted' ? null : new \DateTimeImmutable());
        }

        return $this->reload($id);
    }

    private function reload(int $id): RegistrationRequest
    {
        $request = $this->requests->findById($id);
        $this->assertNotNull($request);

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function journalTypes(): array
    {
        return array_map(
            'strval',
            $this->pdo->query('SELECT event_type FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testScheduleIsPlannedForTheCurrentDecisionAtItsDueDate(): void
    {
        $request = $this->request('refused');
        $due = new \DateTimeImmutable('+24 hours');

        $email = $this->service->schedule($request, $due);

        $this->assertNotNull($email);
        $this->assertSame(DecisionEmail::DECISION_REFUSED, $email->decision);
        $this->assertSame(DecisionEmail::STATE_SCHEDULED, $email->state);
        $this->assertSame($due->format('Y-m-d H:i:s'), $email->dueAt?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $email->attempt);
        $this->assertContains('registration_decision_email_scheduled', $this->journalTypes());
    }

    public function testNothingIsScheduledWithoutADecisionNorBesideAnEmailInPlay(): void
    {
        $this->assertNull($this->service->schedule($this->request(), new \DateTimeImmutable('+1 day')));
        $this->assertNull($this->service->schedule($this->request('withdrawn', 'Noa'), new \DateTimeImmutable()));

        $accepted = $this->request('accepted', 'Ana');
        $this->assertNotNull($this->service->schedule($accepted, new \DateTimeImmutable('+1 day')));
        $this->assertNull($this->service->schedule($accepted, new \DateTimeImmutable('+2 days')));
        $this->assertCount(1, $this->service->history($accepted->id));
    }

    public function testRescheduleMovesAPlannedEmailAndNothingElse(): void
    {
        $email = $this->service->schedule($this->request('accepted'), new \DateTimeImmutable('+1 day'));
        $this->assertNotNull($email);
        $due = new \DateTimeImmutable('+3 days');

        $this->assertTrue($this->service->reschedule($email->id, $due));
        $this->assertSame(
            $due->format('Y-m-d H:i:s'),
            $this->repository->findById($email->id)?->dueAt?->format('Y-m-d H:i:s')
        );
        $this->assertContains('registration_decision_email_rescheduled', $this->journalTypes());

        $this->assertTrue($this->service->claim($email->id));
        $this->assertFalse($this->service->reschedule($email->id, new \DateTimeImmutable('+4 days')));
    }

    /**
     * D8, through the very path a chief takes: refusing an accepted request
     * cancels the acceptance still planned — « Annulé suite à un
     * changement de décision » — and no old e-mail can leave afterwards.
     */
    public function testAChangeOfDecisionCancelsThePlannedEmailAndItCanNeverLeave(): void
    {
        $request = $this->request('accepted');
        $planned = $this->service->schedule($request, new \DateTimeImmutable('-1 minute'));
        $this->assertNotNull($planned);

        $this->statusService->refuse($request, RegistrationRequest::REFUSAL_NO_ROOM);

        $cancelled = $this->repository->findById($planned->id);
        $this->assertSame(DecisionEmail::STATE_CANCELLED, $cancelled?->state);
        $this->assertSame(DecisionEmail::CANCEL_DECISION_CHANGED, $cancelled?->cancelReason);
        $this->assertSame([], $this->service->findDue(10), 'a cancelled e-mail is never due');
        $this->assertFalse($this->service->claim($planned->id));

        try {
            $this->service->begin($this->reload($request->id), DecisionEmail::DECISION_ACCEPTED);
            $this->fail('The old decision cannot be sent any more.');
        } catch (RegistrationException) {
            $this->addToAssertionCount(1);
        }

        // The new decision's e-mail can be planned at once.
        $this->assertNotNull($this->service->schedule($this->reload($request->id), new \DateTimeImmutable('+1 day')));
    }

    /**
     * Every manual transition is a change of decision for the e-mails: a
     * withdrawal and a return to pending cancel too.
     */
    public function testWithdrawingOrRevertingCancelsThePlannedEmailToo(): void
    {
        $withdrawn = $this->request('accepted');
        $reverted = $this->request('refused', 'Noa');
        $first = $this->service->schedule($withdrawn, new \DateTimeImmutable('+1 day'));
        $second = $this->service->schedule($reverted, new \DateTimeImmutable('+1 day'));

        $this->statusService->withdraw($withdrawn);
        $this->statusService->revertToPending($reverted);

        $this->assertSame(DecisionEmail::STATE_CANCELLED, $this->repository->findById((int) $first?->id)?->state);
        $this->assertSame(DecisionEmail::STATE_CANCELLED, $this->repository->findById((int) $second?->id)?->state);
    }

    public function testBeginRefusesAnotherDecisionThanTheRequestsOwn(): void
    {
        foreach ([
            [$this->request(), DecisionEmail::DECISION_ACCEPTED],
            [$this->request('accepted', 'Noa'), DecisionEmail::DECISION_REFUSED],
            [$this->request('refused', 'Ana'), DecisionEmail::DECISION_ACCEPTED],
        ] as [$request, $decision]) {
            try {
                $this->service->begin($request, $decision);
                $this->fail("A {$decision} e-mail for a {$request->status} request must be refused.");
            } catch (RegistrationException) {
                $this->assertSame([], $this->service->history($request->id));
            }
        }
    }

    public function testBeginHandsBackAClaimedAttempt(): void
    {
        $request = $this->request('accepted');

        $attempt = $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);

        $this->assertSame(DecisionEmail::STATE_SENDING, $attempt->state);
        $this->assertNull($attempt->dueAt);
        $this->assertSame($attempt->id, $this->repository->findActiveForRequest($request->id)?->id);
    }

    public function testBeginTakesThePlannedEmailRatherThanASecondOne(): void
    {
        $request = $this->request('accepted');
        $planned = $this->service->schedule($request, new \DateTimeImmutable('+1 day'));

        $attempt = $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);

        $this->assertSame($planned?->id, $attempt->id);
        $this->assertSame(DecisionEmail::STATE_SENDING, $attempt->state);
        $this->assertCount(1, $this->service->history($request->id));
    }

    /**
     * The concurrency the claim exists for, as two callers see it: the
     * first begin() holds the e-mail, the second is told it is in flight
     * and gets nothing to send.
     */
    public function testASecondBeginWhileTheFirstIsInFlightIsRefused(): void
    {
        $request = $this->request('accepted');
        $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('Un envoi de cet e-mail est déjà en cours');
        $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);
    }

    /**
     * Retry and resend: each a row of its own, numbered; a retry after a
     * failure is not a resend, a send after a success is. The failed and
     * the first sent rows stay as they were.
     */
    public function testRetriesAndResendsAreNumberedRowsAndNeverRewriteTheEarlierOnes(): void
    {
        $request = $this->request('refused');

        $first = $this->service->begin($request, DecisionEmail::DECISION_REFUSED);
        $this->service->recordFailure($first, DecisionEmail::FAILURE_MAIL_TRANSPORT, "L'e-mail n'a pas pu partir.");
        $retry = $this->service->begin($request, DecisionEmail::DECISION_REFUSED);
        $this->service->recordSuccess($retry);
        $resend = $this->service->begin($request, DecisionEmail::DECISION_REFUSED);
        $this->service->recordSuccess($resend);

        $history = $this->service->history($request->id);
        $this->assertSame(
            [[1, false, 'failed'], [2, false, 'sent'], [3, true, 'sent']],
            array_map(static fn(DecisionEmail $email) => [$email->attempt, $email->isResend, $email->state], $history)
        );
        $this->assertSame("L'e-mail n'a pas pu partir.", $history[0]->failureMessage);
        $this->assertEquals($history[1]->sentAt, $this->reload($request->id)->refusedEmailSentAt());
    }

    /**
     * The numbering starts again with a new decision: the tries of an
     * earlier decision, and a cancelled plan, are not tries of this one.
     */
    public function testTheAttemptNumberCountsOnlyTheCurrentDecisionsTries(): void
    {
        $request = $this->request('accepted');
        $sent = $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);
        $this->service->recordSuccess($sent);
        $this->service->schedule($this->reload($request->id), new \DateTimeImmutable('+1 day'));

        $this->statusService->refuse($this->reload($request->id), RegistrationRequest::REFUSAL_OTHER);
        $attempt = $this->service->begin($this->reload($request->id), DecisionEmail::DECISION_REFUSED);

        $this->assertSame(1, $attempt->attempt);
        $this->assertFalse($attempt->isResend);
    }

    /**
     * The same decision taken a second time is a new decision: accepted,
     * told, back to pending, accepted again. Its e-mail is a first send,
     * not a resend of the earlier acceptance.
     */
    public function testAReAcceptanceStartsItsOwnNumbering(): void
    {
        $request = $this->request('accepted');
        $this->service->recordSuccess($this->service->begin($request, DecisionEmail::DECISION_ACCEPTED));
        $this->service->recordSuccess($this->service->begin($request, DecisionEmail::DECISION_ACCEPTED));

        $this->statusService->revertToPending($this->reload($request->id));
        $this->statusService->accept($this->reload($request->id));
        $attempt = $this->service->begin($this->reload($request->id), DecisionEmail::DECISION_ACCEPTED);

        $this->assertSame(1, $attempt->attempt);
        $this->assertFalse($attempt->isResend);
    }

    /**
     * A send of the old decision still in flight when the chief changes
     * the decision: the new decision's e-mail can start at once, rather
     * than waiting for stale-send recovery — and the old one still records
     * its own outcome.
     */
    public function testAnInFlightEmailOfTheOldDecisionDoesNotBlockTheNewOne(): void
    {
        $request = $this->request('accepted');
        $inFlight = $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);

        $this->statusService->refuse($this->reload($request->id), RegistrationRequest::REFUSAL_OTHER);
        $attempt = $this->service->begin($this->reload($request->id), DecisionEmail::DECISION_REFUSED);

        $this->assertSame(DecisionEmail::DECISION_REFUSED, $attempt->decision);
        $this->assertSame(DecisionEmail::STATE_SENDING, $attempt->state);
        $this->assertTrue($this->service->recordSuccess($inFlight));
        $this->assertSame(DecisionEmail::STATE_SENT, $this->repository->findById($inFlight->id)?->state);
        $this->assertSame($attempt->id, $this->repository->findActiveForRequest($request->id)?->id);
    }

    public function testAnInterruptedSendIsRecordedAndJournaled(): void
    {
        $request = $this->request('accepted');
        $this->repository->insert(
            $request->id,
            DecisionEmail::DECISION_ACCEPTED,
            DecisionEmail::STATE_SENDING,
            null,
            1,
            false,
            new \DateTimeImmutable('-' . (DecisionEmailService::STALE_SENDING_MINUTES + 5) . ' minutes')
        );

        $attempt = $this->service->begin($request, DecisionEmail::DECISION_ACCEPTED);

        [$interrupted] = $this->service->history($request->id);
        $this->assertSame(DecisionEmail::FAILURE_INTERRUPTED, $interrupted->failureCode);
        $this->assertStringContainsString("il n'est pas certain", (string) $interrupted->failureMessage);
        $this->assertSame(2, $attempt->attempt);
        $this->assertContains('registration_decision_email_interrupted', $this->journalTypes());
    }

    public function testDueEmailsAreClaimedOnceEach(): void
    {
        $email = $this->service->schedule($this->request('accepted'), new \DateTimeImmutable('-1 minute'));
        $this->service->schedule($this->request('accepted', 'Noa'), new \DateTimeImmutable('+1 hour'));

        $due = $this->service->findDue(10);

        $this->assertSame([$email?->id], array_map(static fn(DecisionEmail $e) => $e->id, $due));
        $this->assertTrue($this->service->claim((int) $email?->id));
        $this->assertFalse($this->service->claim((int) $email?->id));
        $this->assertSame([], $this->service->findDue(10));
    }

    public function testTheJournalNamesNoFamily(): void
    {
        $request = $this->request('accepted');
        $email = $this->service->schedule($request, new \DateTimeImmutable('+1 day'));
        $this->service->reschedule((int) $email?->id, new \DateTimeImmutable('+2 days'));

        foreach ($this->pdo->query('SELECT description, context FROM event_log')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Léa', $row['description'] . $row['context']);
            $this->assertStringNotContainsString('lea@example.com', $row['description'] . $row['context']);
        }
    }
}
