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
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private DeskImportService $service;
    private EncryptionService $encryption;
    private string $fixturePath;
    private string $storagePath;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );
        $this->fixturePath = dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv';
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_test_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

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
        $stmt->execute([$this->encryption->encrypt('admin@example.com', 'user_accounts.email')]);

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
            new UnitStaffSectionService($this->pdo),
            new \Core\Member\SectionMembershipService(new \Core\Member\SectionMembershipRepository($this->pdo), new \Core\Config\ScoutYearService($this->pdo)),
            new \Core\Import\RosterReplacementGuard(
                new \Core\Import\RosterComparisonRepository($this->pdo),
                new \Core\ScoutYear\ScoutYearResolver(
                    new \Core\Config\ScoutYearService($this->pdo),
                    new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
                    $memberYearRepo
                )
            ),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo)),
            new \Core\Import\RosterSnapshotRepository($this->pdo),
            new \Core\File\EncryptedFileStorageService(
                new \Core\File\FileRepository($this->pdo),
                $this->encryption,
                $this->storagePath
            ),
            new \Core\Import\ImportDiffCalculator(new \Core\Import\RosterSnapshotRepository($this->pdo))
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

    /**
     * The import no longer deletes the file it is handed — it keeps an
     * encrypted copy of it instead (SECURITY.md §13), and closing the
     * plaintext window is `ImportController`'s `finally`, which owns the
     * deposited file because it is the one that wrote it.
     *
     * That reversal is why the reference dataset can now replay its
     * committed exports without them being consumed.
     */
    public function testTheDepositedFileIsLeftForItsOwnerToDelete(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);

        $this->service->import($tmpFile, $this->scoutYearId, 1);

        $this->assertFileExists($tmpFile);
        unlink($tmpFile);
    }

    public function testTheConsumedCsvIsKeptEncryptedAndAttachedToItsImport(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        $this->service->import($tmpFile, $this->scoutYearId, 1);
        unlink($tmpFile);

        $row = $this->pdo->query(
            'SELECT ij.id AS import_id, f.id AS file_id, f.role_min, f.encrypted, f.owner_type, f.owner_id, f.relative_path
             FROM import_journal ij JOIN files f ON f.id = ij.file_id'
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'The import must have kept its CSV.');
        $this->assertSame('admin', $row['role_min']);
        $this->assertSame(1, (int) $row['encrypted']);
        $this->assertSame('desk_import', $row['owner_type']);
        $this->assertSame((int) $row['import_id'], (int) $row['owner_id']);

        // On disk, and not in clear: the stored bytes must not be the CSV.
        $onDisk = file_get_contents($this->storagePath . '/' . $row['relative_path']);
        $this->assertIsString($onDisk);
        $this->assertStringNotContainsString('Dupont', $onDisk);
        $this->assertStringNotContainsString('jean.dupont@example.com', $onDisk);

        // And it comes back out through the storage service, unchanged.
        $storage = new \Core\File\EncryptedFileStorageService(
            new \Core\File\FileRepository($this->pdo),
            $this->encryption,
            $this->storagePath
        );
        $this->assertStringContainsString('Dupont', $storage->retrieve((int) $row['file_id']));
    }

    public function testTheFirstImportOfASeasonStoresAnUnavailableDiff(): void
    {
        $this->importFixture();

        $importId = (int) $this->pdo->query('SELECT id FROM import_journal')->fetchColumn();
        $diff = (new \Core\Import\ImportJournalRepository($this->pdo))->findDiff($importId);

        $this->assertNotNull($diff, 'Every import stores a diff, even when there is nothing to compare against.');
        $this->assertFalse($diff->available);
        $this->assertSame(\Core\Import\ImportDiff::UNAVAILABLE_FIRST_OF_SEASON, $diff->unavailableReason);
    }

    public function testTheSecondImportStoresARealDiffAgainstTheFirst(): void
    {
        $this->importFixture();
        $firstImportId = (int) $this->pdo->query('SELECT id FROM import_journal')->fetchColumn();

        $this->importFixture();
        $secondImportId = (int) $this->pdo->query('SELECT MAX(id) FROM import_journal')->fetchColumn();

        $diff = (new \Core\Import\ImportJournalRepository($this->pdo))->findDiff($secondImportId);

        $this->assertNotNull($diff);
        $this->assertTrue($diff->available);
        $this->assertSame($firstImportId, $diff->previousImportId);
        // Same file twice: nothing moved, and saying so is not the same
        // as having nothing to say.
        $this->assertTrue($diff->isEmpty());
    }

    public function testTheFirstImportReportsTheFunctionsItHadToCreate(): void
    {
        $this->importFixture();
        $this->importFixture();

        $secondImportId = (int) $this->pdo->query('SELECT MAX(id) FROM import_journal')->fetchColumn();
        $diff = (new \Core\Import\ImportJournalRepository($this->pdo))->findDiff($secondImportId);

        $this->assertNotNull($diff);
        // The second import creates nothing: the fixture's functions,
        // sections and tariffs all already exist.
        $this->assertSame([], $diff->newFunctionIds);
        $this->assertSame([], $diff->newSectionIds);
    }

    public function testTheSnapshotIsAttachedToItsImport(): void
    {
        $this->importFixture();

        $row = $this->pdo->query(
            'SELECT s.import_journal_id, ij.id FROM fees_roster_snapshots s
             JOIN import_journal ij ON ij.id = s.import_journal_id'
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame((int) $row['id'], (int) $row['import_journal_id']);
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
        $firstName = $this->encryption->decrypt($row['first_name_encrypted'], 'member_years.first_name');
        $this->assertContains($firstName, ['Jean', 'Sophie', 'Marc']);
    }

    public function testEmailBlindIndexIsCorrect(): void
    {
        $this->importFixture();

        $expectedIndex = $this->encryption->blindIndex('jean.dupont@example.com', 'email');

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
        $jeanIdx = $this->encryption->blindIndex('jean.dupont@example.com', 'email');
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

    public function testAddressBlindIndexComputedOnImport(): void
    {
        $this->importFixture();

        $stmt = $this->pdo->query(
            'SELECT ma.address_normalized_blind_index FROM member_addresses ma
             JOIN member_years my ON ma.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = \'T001\' ORDER BY ma.id'
        );
        $blindIndexes = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(2, $blindIndexes);
        foreach ($blindIndexes as $blindIndex) {
            $this->assertNotNull($blindIndex);
        }

        $expected = $this->encryption->blindIndex(
            \Core\Member\AddressNormalizer::normalize('Rue de la Liberté', '12', null, '1000'),
            'address'
        );
        $this->assertSame($expected, $blindIndexes[0]);
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

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'SV025L1'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'SV025B1'");
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
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE desk_code = 'SV025L1'");

        $this->service = $this->createService();
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv');
        copy($this->fixturePath, $tmpFile);
        $this->service->import($tmpFile, $this->scoutYearId, 1);

        $stmt = $this->pdo->query("SELECT is_active FROM sections WHERE desk_code = 'SV025L1'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    // ── one Desk row per (function × address) ─────────────────────────

    /**
     * A Desk export emits ONE ROW PER (function × address), so a member
     * with two addresses and one function arrives as that function twice,
     * identical in every field. Written through as-is it became two
     * strictly identical `member_functions` rows and every reader without
     * a DISTINCT counted the person twice.
     */
    public function testTwoAddressesAndOneFunctionProduceOneFunctionRow(): void
    {
        $this->importRows([
            $this->row(['Tiers' => 'T900', 'Nom' => 'Leroy', 'Prenom' => 'Alix', 'Rue' => 'Rue A', 'No' => '1']),
            $this->row(['Tiers' => 'T900', 'Nom' => 'Leroy', 'Prenom' => 'Alix', 'Rue' => 'Rue B', 'No' => '2', "Type d'adresse" => 'Adresse secondaire']),
        ]);

        $this->assertSame(2, $this->addressCountFor('T900'), 'both addresses must survive');
        $this->assertSame(1, $this->functionCountFor('T900'));
    }

    /**
     * The other half of the same rule: two rows differing on ANY field
     * are two real functions. « Animateur / Louveteaux » and « Animateur /
     * Baladins » is the ordinary case, and merging them would lose
     * information rather than restore it.
     */
    public function testTheSameFunctionInTwoSectionsStaysTwoRows(): void
    {
        $this->importRows([
            $this->row(['Tiers' => 'T901', 'Nom' => 'Leroy', 'Prenom' => 'Camille']),
            $this->row([
                'Tiers' => 'T901', 'Nom' => 'Leroy', 'Prenom' => 'Camille',
                'Branche' => 'Baladins', 'Section' => 'SV025B1', 'Fonction principale' => 'false',
            ]),
        ]);

        $this->assertSame(2, $this->functionCountFor('T901'));
    }

    /**
     * Deduplication keeps the FIRST occurrence, so the main function
     * survives and stays at the head of the list —
     * Core\Member\MemberProfile::getMainFunction() falls back to the
     * first entry when nothing is flagged, and a reordering here would be
     * invisible until somebody's page named the wrong section.
     */
    public function testTheMainFunctionSurvivesDeduplicationAndStaysFirst(): void
    {
        $this->importRows([
            $this->row(['Tiers' => 'T902', 'Nom' => 'Leroy', 'Prenom' => 'Dominique']),
            $this->row([
                'Tiers' => 'T902', 'Nom' => 'Leroy', 'Prenom' => 'Dominique',
                'Branche' => 'Baladins', 'Section' => 'SV025B1', 'Fonction principale' => 'false',
            ]),
            $this->row(['Tiers' => 'T902', 'Nom' => 'Leroy', 'Prenom' => 'Dominique', 'Rue' => 'Rue B', "Type d'adresse" => 'Adresse secondaire']),
            $this->row([
                'Tiers' => 'T902', 'Nom' => 'Leroy', 'Prenom' => 'Dominique',
                'Rue' => 'Rue B', "Type d'adresse" => 'Adresse secondaire',
                'Branche' => 'Baladins', 'Section' => 'SV025B1', 'Fonction principale' => 'false',
            ]),
        ]);

        $this->assertSame(2, $this->functionCountFor('T902'));

        $stmt = $this->pdo->prepare(
            'SELECT mf.is_main_function FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = ? ORDER BY mf.id'
        );
        $stmt->execute(['T902']);

        $this->assertSame([1, 0], array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * The view-model both member pages read is collapsed too, so a year
     * imported BEFORE this fix reads correctly without waiting for the
     * next import. The two rows are inserted by hand here for exactly
     * that reason — the importer would no longer write them.
     */
    public function testTwoIdenticalStoredRowsHydrateAsOneFunction(): void
    {
        $this->importRows([$this->row(['Tiers' => 'T903', 'Nom' => 'Leroy', 'Prenom' => 'Eli'])]);

        $stmt = $this->pdo->prepare(
            'SELECT mf.* FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN members m ON my.member_id = m.id WHERE m.desk_id = ?'
        );
        $stmt->execute(['T903']);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($existing);

        $insert = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, start_date, end_date, mandate_end, is_main_function)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $existing['member_year_id'], $existing['function_id'], $existing['section_id'],
            $existing['age_branch_id'], $existing['start_date'], $existing['end_date'],
            $existing['mandate_end'], $existing['is_main_function'],
        ]);

        $this->assertSame(2, $this->functionCountFor('T903'), 'the fixture must really hold two rows');

        $sectionService = new \Core\Member\SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $this->encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );
        $profile = $sectionService->hydrateMemberProfile((int) $existing['member_year_id']);

        self::assertNotNull($profile);
        $this->assertCount(1, $profile->functions);

        $memberService = new \Core\Member\MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            \Core\Database\Connection::withPdo($this->pdo)
        );
        $single = $memberService->getMemberProfile((int) $existing['member_year_id']);

        self::assertNotNull($single);
        $this->assertCount(1, $single->functions, 'the single-member path must collapse them too');
    }

    // ── CSV composition helpers ───────────────────────────────────────

    /**
     * One CSV line, built from the sample export's own header and its
     * second data line, with the named columns overridden. Composing from
     * the committed fixture rather than writing a header out by hand is
     * what keeps these cases valid when the Desk format gains a column.
     *
     * @param array<string, string> $overrides
     * @return array<int, string>
     */
    private function row(array $overrides): array
    {
        $lines = file($this->fixturePath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $header = str_getcsv($lines[0], ';', '"', '\\');
        $template = str_getcsv($lines[1], ';', '"', '\\');

        foreach ($overrides as $column => $value) {
            $index = array_search($column, $header, true);
            self::assertIsInt($index, "unknown CSV column '{$column}'");
            $template[$index] = $value;
        }

        return $template;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function importRows(array $rows): void
    {
        $lines = file($this->fixturePath, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $path = tempnam(sys_get_temp_dir(), 'csv');
        $handle = fopen($path, 'w');
        self::assertNotFalse($handle);
        fwrite($handle, $lines[0] . "\n");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '\\');
        }
        fclose($handle);

        $this->service->import($path, $this->scoutYearId, 1);
    }

    private function functionCountFor(string $deskId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_functions mf
             JOIN member_years my ON mf.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = ?'
        );
        $stmt->execute([$deskId]);

        return (int) $stmt->fetchColumn();
    }

    private function addressCountFor(string $deskId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM member_addresses ma
             JOIN member_years my ON ma.member_year_id = my.id
             JOIN members m ON my.member_id = m.id
             WHERE m.desk_id = ?'
        );
        $stmt->execute([$deskId]);

        return (int) $stmt->fetchColumn();
    }
}
