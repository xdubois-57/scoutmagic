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

    public function testRefuseAndWithdrawAreFinalWithFinalAtSet(): void
    {
        $refused = $this->createRequest();
        $this->service->refuse($refused);
        $this->assertNotNull($this->requestRepository->findById($refused->id)->finalAt);

        $withdrawn = $this->createRequest();
        $this->service->withdraw($withdrawn);
        $this->assertNotNull($this->requestRepository->findById($withdrawn->id)->finalAt);
    }

    public function testRevertToPendingClearsFinalAt(): void
    {
        $request = $this->createRequest();
        $this->service->refuse($request);

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
                $this->service->$method($encoded);
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
        $this->service->refuse($request);
        $refused = $this->requestRepository->findById($request->id);

        $this->expectException(RegistrationException::class);
        $this->service->refuse($refused);
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

        $this->requestRepository->markAcceptedEmailSent($request->id);
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
        $this->service->refuse($request);
        $refused = $this->requestRepository->findById($request->id);

        $this->assertSame('pending', $this->service->visibleStatus($refused));

        $this->requestRepository->markRefusedEmailSent($request->id);
        $afterEmail = $this->requestRepository->findById($request->id);
        $this->assertSame('refused', $this->service->visibleStatus($afterEmail));
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
}
