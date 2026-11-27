<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\ImportedMemberRepository;
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

        $this->service = new ReconciliationService(
            new ImportedMemberRepository($this->pdo, $this->encryption),
            $this->requestRepository,
            $migrationService,
            $journalService
        );

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

    /**
     * Issue #1434: the child's older request is encoded and linked to the
     * member; the family filled the public form again and a chief accepted
     * the new one. The new request stays 'accepted', the case is journaled
     * with ids only, and the next request of the loop is still reconciled.
     */
    public function testAMemberAlreadyLinkedToAnotherRequestIsJournaledAndTheLoopGoesOn(): void
    {
        $olderId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $memberId = $this->insertImportedMember('D8', 'Dupont', 'Léa', '2019-01-01');
        $this->service->reconcileForYear($this->scoutYearId);
        $this->assertSame($memberId, $this->requestRepository->findById($olderId)->linkedMemberId);

        $againId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $otherId = $this->createAcceptedRequest('Martin', 'Noé', '2018-05-05');
        $otherMemberId = $this->insertImportedMember('D9', 'Martin', 'Noé', '2018-05-05');

        $this->service->reconcileForYear($this->scoutYearId);

        $again = $this->requestRepository->findById($againId);
        $this->assertSame('accepted', $again->status);
        $this->assertNull($again->linkedMemberId);
        $this->assertSame($memberId, $this->requestRepository->findById($olderId)->linkedMemberId);

        $other = $this->requestRepository->findById($otherId);
        $this->assertSame('encoded', $other->status);
        $this->assertSame($otherMemberId, $other->linkedMemberId);

        $rows = $this->pdo->query(
            "SELECT level, description, context FROM event_log WHERE event_type = 'registration_reconciliation_already_linked'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('warning', $rows[0]['level']);
        $this->assertSame(
            ['request_id' => $againId, 'linked_request_id' => $olderId, 'member_id' => $memberId],
            json_decode((string) $rows[0]['context'], true)
        );
        foreach (['Dupont', 'Léa'] as $name) {
            $this->assertStringNotContainsString($name, $rows[0]['description']);
            $this->assertStringNotContainsString($name, (string) $rows[0]['context']);
        }

        // And again at the next import: still no exception, still one line per run.
        $this->service->reconcileForYear($this->scoutYearId);
        $this->assertSame('accepted', $this->requestRepository->findById($againId)->status);
    }

    /**
     * The race inside migrate() (review of #1552): another request links the
     * member between migrate()'s own check and its UPDATE, which the UNIQUE
     * index refuses with a duplicate key. Same treatment: journaled, and the
     * next request is still reconciled.
     */
    public function testALinkWonByAConcurrentRequestInsideTheMigrationIsJournaledAndTheLoopGoesOn(): void
    {
        $competingId = $this->createAcceptedRequest('Autre', 'Demande', '2017-03-03');
        $requestId = $this->createAcceptedRequest('Dupont', 'Léa', '2019-01-01');
        $memberId = $this->insertImportedMember('D10', 'Dupont', 'Léa', '2019-01-01');
        $otherId = $this->createAcceptedRequest('Martin', 'Noé', '2018-05-05');
        $otherMemberId = $this->insertImportedMember('D11', 'Martin', 'Noé', '2018-05-05');

        $journalService = new JournalService($this->journalRepository);
        $requests = $this->requestRepository;
        $racing = new class (
            $this->pdo,
            $requests,
            new RegistrationSecondaryEmailRepository($this->pdo, $this->encryption),
            new MemberEmailRepository($this->pdo, $this->encryption),
            $journalService,
            $memberId,
            $competingId
        ) extends MigrationService {
            public function __construct(
                \PDO $pdo,
                private RegistrationRequestRepository $requests,
                RegistrationSecondaryEmailRepository $secondary,
                MemberEmailRepository $memberEmails,
                JournalService $journal,
                private int $contestedMemberId,
                private int $competingRequestId
            ) {
                parent::__construct($pdo, $requests, $secondary, $memberEmails, $journal);
            }

            public function migrate(int $requestId, int $memberId): void
            {
                if ($memberId !== $this->contestedMemberId) {
                    parent::migrate($requestId, $memberId);

                    return;
                }
                // The competing request commits its link first; this write
                // then meets the unique index.
                $this->requests->linkToMemberAndEncode($this->competingRequestId, $memberId, new \DateTimeImmutable());
                $e = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
                $e->errorInfo = ['23000', 1062, "Duplicate entry for key 'idx_rr_linked_member'"];
                throw $e;
            }
        };
        $service = new ReconciliationService(
            new ImportedMemberRepository($this->pdo, $this->encryption),
            $this->requestRepository,
            $racing,
            $journalService
        );

        $service->reconcileForYear($this->scoutYearId);

        $this->assertSame('accepted', $this->requestRepository->findById($requestId)->status);
        $this->assertSame($otherMemberId, $this->requestRepository->findById($otherId)->linkedMemberId);
        $context = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_reconciliation_already_linked'"
        )->fetchColumn();
        $this->assertSame(
            ['request_id' => $requestId, 'linked_request_id' => $competingId, 'member_id' => $memberId],
            json_decode((string) $context, true)
        );
    }

    public function testEmptyYearIsANoOp(): void
    {
        $this->service->reconcileForYear($this->scoutYearId);
        $this->expectNotToPerformAssertions();
    }
}
