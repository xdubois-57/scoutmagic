<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\AgeBranchRepository;
use Core\Import\DeskCsvParser;
use Core\Import\DeskImportService;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class DeskImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private DeskImportService $service;
    private EncryptionService $encryption;
    private string $fixturePath;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );
        $this->fixturePath = dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv';

        // Create scout year
        $stmt = $this->pdo->prepare(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)"
        );
        $stmt->execute();
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create an admin user as the importer
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, 'admin_idx', 1)"
        );
        $stmt->execute([$this->encryption->encrypt('admin@example.com')]);

        $this->service = $this->createService();
    }

    private function createService(): DeskImportService
    {
        $functionRepo = new FunctionRepository($this->pdo);
        $ageBranchRepo = new AgeBranchRepository($this->pdo);
        $sectionRepo = new ImportSectionRepository($this->pdo);
        $feeRepo = new FeeCategoryRepository($this->pdo);
        $memberRepo = new MemberRepository($this->pdo);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $importJournalRepo = new ImportJournalRepository($this->pdo);
        $userAccountRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $mappingResolver = new MappingResolver($functionRepo, $ageBranchRepo, $sectionRepo, $feeRepo);
        $parser = new DeskCsvParser();

        return new DeskImportService(
            $this->pdo, $this->encryption, $parser, $mappingResolver,
            $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo,
            new UnitStaffSectionService($this->pdo)
        );
    }

    private function importFixture(): \Core\Import\ImportResult
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        return $this->service->import($tmpFile, $this->scoutYearId, 1);
    }

    public function testFullImportCycleCreatesMembersAndFunctions(): void
    {
        $result = $this->importFixture();

        $this->assertSame(3, $result->memberCount);
        $this->assertSame(5, $result->lineCount);
        $this->assertGreaterThan(0, $result->newFunctionsCount);
    }

    public function testMembersCreatedInDatabase(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM members');
        $this->assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testMemberYearsCreated(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM member_years');
        $this->assertSame(3, (int) $stmt->fetchColumn());
    }

    public function testReImportUpdatesExistingDataNoDuplicates(): void
    {
        $this->importFixture();

        // Re-import (need a fresh service because MappingResolver has state)
        $this->service = $this->createService();
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        $result = $this->service->import($tmpFile, $this->scoutYearId, 1);

        // Should still have 3 members, not 6
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM members');
        $this->assertSame(3, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM member_years');
        $this->assertSame(3, (int) $stmt->fetchColumn());

        $this->assertSame(3, $result->memberCount);
    }

    public function testImportJournalEntryCreated(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM import_journal');
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query('SELECT * FROM import_journal LIMIT 1');
        $journal = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($this->scoutYearId, (int) $journal['scout_year_id']);
        $this->assertSame(3, (int) $journal['member_count']);
    }

    public function testImportCreatesStaffduSection(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'STAFFDU'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function testImportSyncsExistingAdminFunctionsIntoStaffdu(): void
    {
        $this->importFixture();

        // The fixture's "Intendant d'unité" CSV row has no Section/Branche
        // column, so it always imports with section_id NULL — the exact
        // shape a future chef d'unité function has once confirmed on Config
        // Desk (role is only ever set post-import, never by the CSV itself).
        $stmt = $this->pdo->prepare(
            'SELECT mf.id, mf.function_id FROM member_functions mf
             JOIN functions f ON mf.function_id = f.id
             WHERE mf.section_id IS NULL AND f.desk_code = ?'
        );
        $stmt->execute(["Intendant d'unité"]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'fixture must contain a function row with no section');
        $functionId = (int) $row['function_id'];

        // Simulate that function being confirmed as chef d'unité on Config
        // Desk before the next import runs.
        $this->pdo->exec("UPDATE functions SET role = 'admin' WHERE id = {$functionId}");

        $this->service = $this->createService();
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        $this->service->import($tmpFile, $this->scoutYearId, 1);

        $stmt = $this->pdo->query("SELECT id FROM sections WHERE desk_code = 'STAFFDU'");
        $staffduId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare('SELECT section_id FROM member_functions WHERE function_id = ?');
        $stmt->execute([$functionId]);
        $this->assertSame($staffduId, (int) $stmt->fetchColumn());
    }

    public function testCsvFileDeletedAfterImport(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);

        $this->service->import($tmpFile, $this->scoutYearId, 1);

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testPersonalDataIsEncryptedInDatabase(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query('SELECT first_name_encrypted, last_name_encrypted FROM member_years LIMIT 1');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Encrypted data should not be readable as plain text
        $this->assertNotSame('Jean', $row['first_name_encrypted']);
        $this->assertNotSame('Dupont', $row['last_name_encrypted']);

        // But decryption should work
        $firstName = $this->encryption->decrypt($row['first_name_encrypted']);
        $this->assertContains($firstName, ['Jean', 'Sophie', 'Marc']);
    }

    public function testEmailBlindIndexIsCorrect(): void
    {
        $this->importFixture();

        $expectedIndex = $this->encryption->blindIndex('jean.dupont@example.com');

        $stmt = $this->pdo->prepare('SELECT id FROM member_years WHERE email_blind_index = ?');
        $stmt->execute([$expectedIndex]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
    }

    public function testUserAccountsAutoCreatedForMembersWithEmail(): void
    {
        $this->importFixture();

        // Should have created accounts for 3 members + 1 existing admin = at least 4
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM user_accounts');
        $count = (int) $stmt->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $count);

        // Check Jean's account
        $jeanIdx = $this->encryption->blindIndex('jean.dupont@example.com');
        $stmt = $this->pdo->prepare('SELECT id FROM user_accounts WHERE email_blind_index = ?');
        $stmt->execute([$jeanIdx]);
        $this->assertNotFalse($stmt->fetch());
    }

    public function testAddressesStoredCorrectly(): void
    {
        $this->importFixture();

        // T001 (Jean) has 2 addresses
        $stmt = $this->pdo->query(
            'SELECT COUNT(*) FROM member_addresses ma
             JOIN member_years my ON ma.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = \'T001\''
        );
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testFunctionsStoredCorrectly(): void
    {
        $this->importFixture();

        // T002 (Sophie) has 2 functions
        $stmt = $this->pdo->query(
            'SELECT COUNT(*) FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = \'T002\''
        );
        $this->assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testSectionsReferencedByImportAreActive(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'Meute Akela'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'Ribambelle'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSectionNotReferencedByImportBecomesInactive(): void
    {
        // A section left over from a previous year, no longer in the CSV.
        $stmt = $this->pdo->prepare("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ROU', 'Route', 60)");
        $stmt->execute();
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, is_active) VALUES (?, ?, 1)');
        $stmt->execute(['OLD_SECTION', $branchId]);
        $oldSectionId = (int) $this->pdo->lastInsertId();

        $this->importFixture();

        $stmt = $this->pdo->prepare('SELECT is_active FROM sections WHERE id = ?');
        $stmt->execute([$oldSectionId]);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testReimportReactivatesPreviouslyInactiveSection(): void
    {
        $this->importFixture();
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE desk_code = 'Meute Akela'");

        $this->service = $this->createService();
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        $this->service->import($tmpFile, $this->scoutYearId, 1);

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'Meute Akela'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
