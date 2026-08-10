<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\MigrationService;
use Modules\Registration\Service\RegistrationException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The single migration path both automatic reconciliation and manual
 * linking share (module spec: "un seul chemin de code, pas deux"):
 * confirmed secondary emails move to the real member, the write is
 * transactional, and it's idempotent-safe to retry.
 *
 * @group database
 */
class MigrationServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private RegistrationSecondaryEmailRepository $secondaryEmailRepository;
    private MemberEmailRepository $memberEmailRepository;
    private MigrationService $service;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $this->memberEmailRepository = new MemberEmailRepository($this->pdo, $encryption);
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $this->service = new MigrationService(
            $this->pdo, $this->requestRepository, $this->secondaryEmailRepository, $this->memberEmailRepository, $journalService
        );

        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createAcceptedRequest(): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        return $created['id'];
    }

    public function testMigrateEncodesTheRequestAndLinksTheMember(): void
    {
        $requestId = $this->createAcceptedRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M1');

        $this->service->migrate($requestId, $memberId);

        $updated = $this->requestRepository->findById($requestId);
        $this->assertSame('encoded', $updated->status);
        $this->assertSame($memberId, $updated->linkedMemberId);
        $this->assertNotNull($updated->finalAt);
    }

    private function createConfirmedSecondaryEmail(int $requestId, string $email): void
    {
        $id = $this->secondaryEmailRepository->create($requestId, $email, 'hash', new \DateTimeImmutable('+1 day'));
        $this->secondaryEmailRepository->markValid($id);
    }

    public function testConfirmedSecondaryEmailMigratesToTheMember(): void
    {
        $requestId = $this->createAcceptedRequest();
        $this->createConfirmedSecondaryEmail($requestId, 'secondary@example.com');
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M2');

        $this->service->migrate($requestId, $memberId);

        $memberEmail = $this->memberEmailRepository->findByMemberAndEmail($memberId, 'secondary@example.com');
        $this->assertNotNull($memberEmail);
        $this->assertSame(MemberEmail::STATUS_VALID, $memberEmail->status);
    }

    public function testUnconfirmedSecondaryEmailDoesNotMigrate(): void
    {
        $requestId = $this->createAcceptedRequest();
        $this->secondaryEmailRepository->create($requestId, 'pending@example.com', 'hash', new \DateTimeImmutable('+1 day'));
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M3');

        $this->service->migrate($requestId, $memberId);

        $this->assertNull($this->memberEmailRepository->findByMemberAndEmail($memberId, 'pending@example.com'));
    }

    public function testMigratingAgainToTheSameMemberIsANoOp(): void
    {
        $requestId = $this->createAcceptedRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M4');
        $this->service->migrate($requestId, $memberId);
        $firstFinalAt = $this->requestRepository->findById($requestId)->finalAt;

        $this->service->migrate($requestId, $memberId);

        $updated = $this->requestRepository->findById($requestId);
        $this->assertSame('encoded', $updated->status);
        $this->assertEquals($firstFinalAt, $updated->finalAt);
    }

    public function testMigratingToAMemberAlreadyLinkedToAnotherRequestIsRefused(): void
    {
        $requestId1 = $this->createAcceptedRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M5');
        $this->service->migrate($requestId1, $memberId);

        $requestId2 = $this->createAcceptedRequest();

        $this->expectException(RegistrationException::class);
        $this->service->migrate($requestId2, $memberId);
    }

    public function testFailedMigrationDoesNotPartiallyApply(): void
    {
        // Force a failure mid-transaction by linking the member to a
        // DIFFERENT request first, then attempting to migrate a second
        // request to that same member with a secondary email present —
        // the whole operation (including the secondary email move) must
        // never partially apply.
        $requestId1 = $this->createAcceptedRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M6');
        $this->service->migrate($requestId1, $memberId);

        $requestId2 = $this->createAcceptedRequest();
        $this->createConfirmedSecondaryEmail($requestId2, 'blocked@example.com');

        try {
            $this->service->migrate($requestId2, $memberId);
            $this->fail('Expected RegistrationException');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertSame('accepted', $this->requestRepository->findById($requestId2)->status);
        $this->assertNull($this->memberEmailRepository->findByMemberAndEmail($memberId, 'blocked@example.com'));
    }

    public function testMigrationJournalNeverContainsPersonalData(): void
    {
        $requestId = $this->createAcceptedRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M7');

        $this->service->migrate($requestId, $memberId);

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Léa', (string) $row['description']);
            $this->assertStringNotContainsString('Dupont', (string) $row['description']);
            $this->assertStringNotContainsString('Léa', (string) $row['context']);
            $this->assertStringNotContainsString('Dupont', (string) $row['context']);
        }
    }
}
