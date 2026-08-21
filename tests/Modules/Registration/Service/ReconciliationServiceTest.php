<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\MigrationService;
use Modules\Registration\Service\ReconciliationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Desk-import reconciliation (module spec): single match migrates
 * automatically, no match leaves the request untouched, and an ambiguous
 * match on either side is refused outright — with never a name in the
 * journal, only counts/ids (real case cited by the spec: homonym twins).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReconciliationServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requestRepository;
    private EncryptionService $encryption;
    private ReconciliationService $service;
    private JournalRepository $journalRepository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $this->encryption);
        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $this->encryption);
        $memberEmailRepository = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->journalRepository = new JournalRepository($this->pdo);
        $journalService = new JournalService($this->journalRepository);
        $migrationService = new MigrationService($this->pdo, $this->requestRepository, $secondaryEmailRepository, $memberEmailRepository, $journalService);

        $this->service = new ReconciliationService($this->pdo, $this->requestRepository, $this->encryption, $migrationService, $journalService);

        $this->scoutYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function createAcceptedRequest(string $lastName, string $firstName, string $birthDate): int
    {
        $created = $this->requestRepository->create($this->scoutYearId, [
            'parent_name' => 'Parent', 'child_last_name' => $lastName, 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);
        $this->requestRepository->updateStatus($created['id'], 'accepted', null);

        return $created['id'];
    }

    private function insertImportedMember(string $deskId, string $lastName, string $firstName, string $birthDate): int
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, $deskId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'), $this->encryption->encrypt($lastName, 'member_years.last_name'), $this->encryption->encrypt($birthDate, 'member_years.birth_date'),
        ]);

        return $memberId;
    }

    public function testSingleMatchMigratesAutomatically(): void
    {
        $requestId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $memberId = $this->insertImportedMember('D1', 'Dupont', 'Léa', '2019-01-01');

        $this->service->reconcileForYear($this->scoutYearId);

        $updated = $this->requestRepository->findById($requestId);
        $this->assertSame('encoded', $updated->status);
        $this->assertSame($memberId, $updated->linkedMemberId);
    }

    public function testNoMatchLeavesRequestAccepted(): void
    {
        $requestId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D2', 'Autre', 'Enfant', '2018-01-01');

        $this->service->reconcileForYear($this->scoutYearId);

        $this->assertSame('accepted', $this->requestRepository->findById($requestId)->status);
    }

    public function testAmbiguousOnMemberSideIsNeverMigrated(): void
    {
        // Twins imported from Desk, sharing the same name+DOB.
        $requestId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D3', 'Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D4', 'Dupont', 'Léa', '2019-01-01');

        $this->service->reconcileForYear($this->scoutYearId);

        $this->assertSame('accepted', $this->requestRepository->findById($requestId)->status);
    }

    public function testAmbiguousOnRequestSideIsNeverMigrated(): void
    {
        $requestId1 = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $requestId2 = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D5', 'Dupont', 'Léa', '2019-01-01');

        $this->service->reconcileForYear($this->scoutYearId);

        $this->assertSame('accepted', $this->requestRepository->findById($requestId1)->status);
        $this->assertSame('accepted', $this->requestRepository->findById($requestId2)->status);
    }

    public function testAmbiguousCaseIsJournaledWithoutAnyName(): void
    {
        $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D6', 'Dupont', 'Léa', '2019-01-01');
        $this->insertImportedMember('D7', 'Dupont', 'Léa', '2019-01-01');

        $this->service->reconcileForYear($this->scoutYearId);

        $stmt = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'registration_reconciliation_ambiguous'");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString('Dupont', $rows[0]['description']);
        $this->assertStringNotContainsString('Léa', $rows[0]['description']);
        $this->assertStringNotContainsString('Dupont', $rows[0]['context']);
        $this->assertStringNotContainsString('Léa', $rows[0]['context']);
        $this->assertStringContainsString('member_ids', $rows[0]['context']);
    }

    public function testEmptyYearIsANoOp(): void
    {
        $this->service->reconcileForYear($this->scoutYearId);
        $this->expectNotToPerformAssertions();
    }
}
