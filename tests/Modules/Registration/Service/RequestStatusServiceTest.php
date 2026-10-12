<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Every manual transition (accept/refuse/withdraw/revert), rejection of an
 * inconsistent one, `final_at` bookkeeping, and the email-gated
 * visibleStatus() decoupling rule (module spec: the tracking page must
 * never reveal an acceptance/refusal before the matching email is sent).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RequestStatusServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private RequestStatusService $service;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->service = new RequestStatusService($this->requestRepository, new JournalService(new JournalRepository($this->pdo)));
        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createRequest(): RegistrationRequest
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $this->requestRepository->findById($created['id']);
    }

    public function testAcceptTransitionsPendingToAccepted(): void
    {
        $request = $this->createRequest();

        $this->service->accept($request);

        $updated = $this->requestRepository->findById($request->id);
        $this->assertSame('accepted', $updated->status);
        // 'accepted' is not itself a final state (only encodée/refusée/
        // retirée are) — the retention clock hasn't started yet.
        $this->assertNull($updated->finalAt);
    }

    /**
     * An acceptance taken by the site itself (issue #843, IT-10) says so in
     * its journal entry; one taken by a chief does not.
     */
    public function testAnAutomaticAcceptanceIsJournaledApartFromAManualOne(): void
    {
        $this->service->accept($this->createRequest());
        $this->service->accept($this->createRequest(), true);

        $rows = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'registration_request_status_changed'
             ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey('automatic', (array) json_decode((string) $rows[0]['context'], true));
        $this->assertStringNotContainsString('automatique', (string) $rows[0]['description']);
        $this->assertTrue(json_decode((string) $rows[1]['context'], true)['automatic'] ?? false);
        $this->assertStringEndsWith('(acceptation automatique)', (string) $rows[1]['description']);
    }

    public function testRefuseAndWithdrawAreFinalWithFinalAtSet(): void
    {
        $refused = $this->createRequest();
        $this->service->refuse($refused, RegistrationRequest::REFUSAL_OTHER);
        $this->assertNotNull($this->requestRepository->findById($refused->id)->finalAt);

        $withdrawn = $this->createRequest();
        $this->service->withdraw($withdrawn);
        $this->assertNotNull($this->requestRepository->findById($withdrawn->id)->finalAt);
    }

    public function testRevertToPendingClearsFinalAt(): void
    {
        $request = $this->createRequest();
        $this->service->refuse($request, RegistrationRequest::REFUSAL_OTHER);

        $this->service->revertToPending($this->requestRepository->findById($request->id));

        $updated = $this->requestRepository->findById($request->id);
        $this->assertSame('pending', $updated->status);
        $this->assertNull($updated->finalAt);
    }

    public function testAcceptedCanBeRefusedWithdrawnOrRevertedToPending(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        $accepted = $this->requestRepository->findById($request->id);

        $this->service->revertToPending($accepted);
        $this->assertSame('pending', $this->requestRepository->findById($request->id)->status);
    }

    public function testEncodedHasNoManualTransitions(): void
    {
        $request = $this->createRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $this->requestRepository->updateStatus($request->id, 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($request->id, $memberId, new \DateTimeImmutable());
        $encoded = $this->requestRepository->findById($request->id);

        foreach (['accept', 'refuse', 'withdraw', 'revertToPending'] as $method) {
            try {
                $method === 'refuse'
                    ? $this->service->refuse($encoded, RegistrationRequest::REFUSAL_NO_ROOM)
                    : $this->service->$method($encoded);
                $this->fail("Expected RegistrationException for {$method}() on an encoded request");
            } catch (RegistrationException) {
                // expected
            }
        }
        $this->assertSame('encoded', $this->requestRepository->findById($request->id)->status);
    }

    public function testRefusingAnAlreadyRefusedRequestIsRejected(): void
    {
        $request = $this->createRequest();
        $this->service->refuse($request, RegistrationRequest::REFUSAL_OTHER);
        $refused = $this->requestRepository->findById($request->id);

        $this->expectException(RegistrationException::class);
        $this->service->refuse($refused, RegistrationRequest::REFUSAL_OTHER);
    }

    public function testAcceptingAnAlreadyAcceptedRequestIsRejected(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        $accepted = $this->requestRepository->findById($request->id);

        $this->expectException(RegistrationException::class);
        $this->service->accept($accepted);
    }

    public function testVisibleStatusHidesAcceptanceUntilEmailSent(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        $accepted = $this->requestRepository->findById($request->id);

        $this->assertSame('pending', $this->service->visibleStatus($accepted));

        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'accepted');
        $afterEmail = $this->requestRepository->findById($request->id);
        $this->assertSame('accepted', $this->service->visibleStatus($afterEmail));
    }

    public function testVisibleStatusHidesEncodedUntilAcceptanceEmailSent(): void
    {
        $request = $this->createRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M2');
        $this->requestRepository->updateStatus($request->id, 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($request->id, $memberId, new \DateTimeImmutable());
        $encoded = $this->requestRepository->findById($request->id);

        // Encoded (migrated) without the acceptance email ever having been
        // sent — still gated exactly like a plain "accepted".
        $this->assertSame('pending', $this->service->visibleStatus($encoded));
    }

    public function testVisibleStatusHidesRefusalUntilEmailSent(): void
    {
        $request = $this->createRequest();
        $this->service->refuse($request, RegistrationRequest::REFUSAL_OTHER);
        $refused = $this->requestRepository->findById($request->id);

        $this->assertSame('pending', $this->service->visibleStatus($refused));

        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'refused');
        $afterEmail = $this->requestRepository->findById($request->id);
        $this->assertSame('refused', $this->service->visibleStatus($afterEmail));
    }

    public function testVisibleStatusShowsEncodedOnceTheAcceptanceEmailWasSent(): void
    {
        $request = $this->createRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M3');
        $this->service->accept($request);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'accepted');
        // Encoding does not change the decision the family was told: the
        // acceptance e-mail still counts for it.
        $this->requestRepository->linkToMemberAndEncode($request->id, $memberId, new \DateTimeImmutable());

        $this->assertSame('encoded', $this->service->visibleStatus($this->requestRepository->findById($request->id)));
    }

    /**
     * R3: the acceptance e-mail went out, then the chief refused. The
     * family must keep seeing « en attente » until the REFUSAL e-mail
     * leaves — the old acceptance date announces nothing about it.
     */
    public function testAnEarlierDecisionsEmailNeverRevealsTheNewDecision(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'accepted');
        $this->assertSame('accepted', $this->service->visibleStatus($this->requestRepository->findById($request->id)));

        $this->service->refuse($this->requestRepository->findById($request->id), RegistrationRequest::REFUSAL_OTHER);
        $refused = $this->requestRepository->findById($request->id);
        $this->assertSame('pending', $this->service->visibleStatus($refused));
        $this->assertNull($refused->decisionEmailSentAt);

        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'refused');
        $this->assertSame('refused', $this->service->visibleStatus($this->requestRepository->findById($request->id)));
    }

    /**
     * R3, the same decision taken twice: accepted and told, back to
     * pending, accepted again. The first acceptance e-mail belonged to the
     * first decision; the second acceptance waits for its own.
     */
    public function testAReAcceptanceWaitsForItsOwnEmail(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'accepted');

        $this->service->revertToPending($this->requestRepository->findById($request->id));
        $this->service->accept($this->requestRepository->findById($request->id));

        $this->assertSame('pending', $this->service->visibleStatus($this->requestRepository->findById($request->id)));

        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $request->id, 'accepted');
        $this->assertSame('accepted', $this->service->visibleStatus($this->requestRepository->findById($request->id)));
    }

    /**
     * D8: a change of decision cancels the e-mail still planned for the old
     * one, and the journal entry of the change says how many — a count,
     * nothing about the family.
     */
    public function testAChangeOfDecisionCancelsTheScheduledEmailAndJournalsTheCount(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);
        $repository = new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo);
        $scheduled = $repository->insert(
            $request->id,
            'accepted',
            'scheduled',
            new \DateTimeImmutable('+1 day'),
            1,
            false,
            new \DateTimeImmutable()
        );
        $this->assertNotNull($scheduled);

        $this->service->refuse($this->requestRepository->findById($request->id), RegistrationRequest::REFUSAL_OTHER);

        $email = $repository->findById($scheduled);
        $this->assertSame('cancelled', $email?->state);
        $this->assertSame('decision_changed', $email?->cancelReason);

        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_request_status_changed'
             ORDER BY id DESC LIMIT 1"
        )->fetchColumn(), true);
        $this->assertSame(1, $context['decision_emails_cancelled'] ?? null);
    }

    public function testVisibleStatusPassesThroughPendingAndWithdrawnUnchanged(): void
    {
        $pending = $this->createRequest();
        $this->assertSame('pending', $this->service->visibleStatus($pending));

        $withdrawRequest = $this->createRequest();
        $this->service->withdraw($withdrawRequest);
        $withdrawn = $this->requestRepository->findById($withdrawRequest->id);
        $this->assertSame('withdrawn', $this->service->visibleStatus($withdrawn));
    }

    public function testStatusChangeJournalNeverContainsPersonalData(): void
    {
        $request = $this->createRequest();
        $this->service->accept($request);

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Léa', (string) $row['description']);
            $this->assertStringNotContainsString('Dupont', (string) $row['description']);
            $this->assertStringNotContainsString('Léa', (string) $row['context']);
            $this->assertStringNotContainsString('Dupont', (string) $row['context']);
        }
    }

    /**
     * The refusal reason travels with the refusal (issue #843, D6): stored
     * on the request, journalled as a code, and gone once the request is
     * put back in the pending list.
     */
    public function testARefusalCarriesItsReasonUntilTheRequestIsReverted(): void
    {
        $request = $this->createRequest();

        $this->service->refuse($request, RegistrationRequest::REFUSAL_NO_ROOM);

        $refused = $this->requestRepository->findById($request->id);
        $this->assertSame('refused', $refused?->status);
        $this->assertSame(RegistrationRequest::REFUSAL_NO_ROOM, $refused->refusalReason);
        $context = (string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_request_status_changed' "
            . 'ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        $this->assertSame(['request_id' => $request->id, 'refusal_reason' => 'no_room'], json_decode($context, true));

        $this->service->revertToPending($refused);
        $this->assertNull($this->requestRepository->findById($request->id)?->refusalReason);
    }

    /**
     * Every refusal carries its reason since IT-04 — the dialog, the fiche
     * and the bulk refusal all ask for one: an empty one is refused like an
     * unknown one, before anything is written.
     */
    public function testARefusalWithoutAReasonChangesNothing(): void
    {
        $request = $this->createRequest();

        try {
            $this->service->refuse($request, '');
            $this->fail('a refusal without a reason was accepted');
        } catch (RegistrationException $e) {
            $this->assertSame(RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED, $e->getMessage());
        }

        $this->assertSame('pending', $this->requestRepository->findById($request->id)?->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }

    public function testOnlyTheTwoReasonsAreRefusalReasons(): void
    {
        $this->assertTrue(RequestStatusService::isRefusalReason(RegistrationRequest::REFUSAL_NO_ROOM));
        $this->assertTrue(RequestStatusService::isRefusalReason(RegistrationRequest::REFUSAL_OTHER));
        $this->assertFalse(RequestStatusService::isRefusalReason(null));
        $this->assertFalse(RequestStatusService::isRefusalReason(''));
        $this->assertFalse(RequestStatusService::isRefusalReason('full'));
        $this->assertFalse(RequestStatusService::isRefusalReason('Pas de place'));
    }

    /**
     * An unknown reason is refused before anything is written.
     */
    public function testAnUnknownRefusalReasonChangesNothing(): void
    {
        $request = $this->createRequest();

        try {
            $this->service->refuse($request, 'full');
            $this->fail('an unknown reason was accepted');
        } catch (RegistrationException $e) {
            $this->assertSame(RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED, $e->getMessage());
        }

        $this->assertSame('pending', $this->requestRepository->findById($request->id)?->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn());
    }
}
