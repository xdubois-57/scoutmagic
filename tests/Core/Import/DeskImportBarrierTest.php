<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
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
use Core\Import\RosterComparisonRepository;
use Core\Import\RosterReplacementGuard;
use Core\Import\RosterReplacementRefusedException;
use Core\Import\RosterReplacementVerdict;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionMembershipService;
use Core\Member\UnitStaffSectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The barrier as the import itself sees it: refuse before writing, let a
 * typed confirmation through where one is legitimate, and never let it
 * through where the site would end up with no administrator.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskImportBarrierTest extends TestCase
{
    private \PDO $pdo;
    private DeskImportService $service;
    private string $fixturePath;
    private string $storagePath;
    private int $scoutYearId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fixturePath = dirname(__DIR__, 2) . '/fixtures/desk_export_sample.csv';
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_test_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Deliberately NOT a super-admin: the whole point of the hard
        // invariant is the chef d'unité who is admin by Desk function
        // alone, with no separate account to fall back on.
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, 'cu_idx', 0)"
        );
        $stmt->execute([$encryption->encrypt('cu@example.com', 'user_accounts.email')]);

        $functionRepo = new FunctionRepository($this->pdo);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->service = new DeskImportService(
            $this->pdo,
            $encryption,
            new DeskCsvParser(),
            new MappingResolver(
                $functionRepo,
                new AgeBranchRepository($this->pdo),
                new ImportSectionRepository($this->pdo),
                new FeeCategoryRepository($this->pdo)
            ),
            new MemberRepository($this->pdo),
            $memberYearRepo,
            new ImportJournalRepository($this->pdo),
            new UserAccountRepository($this->pdo, $encryption),
            new UnitStaffSectionService($this->pdo),
            new SectionMembershipService(new SectionMembershipRepository($this->pdo), new ScoutYearService($this->pdo)),
            new RosterReplacementGuard(
                new RosterComparisonRepository($this->pdo),
                new ScoutYearResolver(
                    new ScoutYearService($this->pdo),
                    new SettingService(new SettingRepository($this->pdo)),
                    $memberYearRepo
                )
            ),
            new JournalService(new JournalRepository($this->pdo)),
            new \Core\Import\RosterSnapshotRepository($this->pdo),
            new \Core\File\EncryptedFileStorageService(
                new \Core\File\FileRepository($this->pdo),
                $encryption,
                $this->storagePath
            ),
            new \Core\Import\ImportDiffCalculator(new \Core\Import\RosterSnapshotRepository($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testAFilteredExportIsRefusedAndWritesNothing(): void
    {
        $this->importFullRoster();
        $this->assertSame(3, $this->countActiveMembers());

        // Only the Baladins row — an export filtered on one section.
        $filtered = $this->csvKeeping(['T003']);

        try {
            $this->service->import($filtered, $this->scoutYearId, 1);
            $this->fail('The filtered export should have been refused.');
        } catch (RosterReplacementRefusedException $e) {
            $this->assertSame(RosterReplacementVerdict::FILTERED_EXPORT, $e->assessment->verdict);
            $this->assertSame(2, $e->assessment->deactivatedCount);
        }

        // Nothing was written: the roster is exactly as it was.
        $this->assertSame(3, $this->countActiveMembers());
        $this->assertSame(1, $this->countImportJournalRows());
    }

    public function testTheSameFilePassesWithTheConfirmationWord(): void
    {
        $this->importFullRoster();
        $filtered = $this->csvKeeping(['T003']);

        $result = $this->service->import($filtered, $this->scoutYearId, 1, true);

        $this->assertSame(1, $result->memberCount);
        $this->assertSame(1, $this->countActiveMembers());
        $this->assertSame(2, $this->countImportJournalRows());
    }

    public function testARefusalIsJournaledAtSecurityLevelWithCountersOnly(): void
    {
        $this->importFullRoster();
        $filtered = $this->csvKeeping(['T003']);

        try {
            $this->service->import($filtered, $this->scoutYearId, 1);
        } catch (RosterReplacementRefusedException) {
            // expected
        }

        $row = $this->lastJournalEntry();
        $this->assertSame('desk_import_barrier_triggered', $row['event_type']);
        $this->assertSame('security', $row['level']);
        $this->assertStringNotContainsString('T00', (string) $row['context']);
        $this->assertStringNotContainsString('SV025', (string) $row['context']);
        $this->assertStringContainsString('"deactivated_count":2', (string) $row['context']);
    }

    public function testAnOverrideIsJournaledToo(): void
    {
        $this->importFullRoster();
        $filtered = $this->csvKeeping(['T003']);

        $this->service->import($filtered, $this->scoutYearId, 1, true);

        $entries = array_column($this->journalEntries(), 'event_type');
        $this->assertContains('desk_import_barrier_overridden', $entries);
    }

    public function testAnImportRemovingTheLastAdminIsRefusedEvenWithTheWord(): void
    {
        $this->importFullRoster();

        // A chef d'unité is only a chef d'unité once the function's role
        // has been confirmed on Config Desk — do that, then hand the
        // import a file that no longer carries them.
        $stmt = $this->pdo->prepare("UPDATE functions SET role = 'admin', confirmed = 1 WHERE desk_code = ?");
        $stmt->execute(["Intendant d'unité"]);

        $withoutTheChief = $this->csvKeeping(['T001', 'T003']);

        try {
            $this->service->import($withoutTheChief, $this->scoutYearId, 1, true);
            $this->fail('An import leaving no administrator must be refused even when confirmed.');
        } catch (RosterReplacementRefusedException $e) {
            $this->assertSame(RosterReplacementVerdict::NO_ADMIN_LEFT, $e->assessment->verdict);
            $this->assertFalse($e->assessment->verdict->allowsOverride());
        }

        $this->assertSame(3, $this->countActiveMembers());
    }

    public function testAnOrdinaryReImportIsNotDisturbed(): void
    {
        $this->importFullRoster();
        $result = $this->service->import($this->csvKeeping(['T001', 'T002', 'T003']), $this->scoutYearId, 1);

        $this->assertSame(3, $result->memberCount);
        $this->assertSame(3, $this->countActiveMembers());
    }

    /* ------------------------------------------------------------------ */

    /**
     * The whole sample export, through a copy.
     *
     * `DeskImportService::import()` deletes the file it is handed, so
     * handing it the committed fixture directly would delete the fixture
     * — the very hazard `tests/fixtures/reference-dataset/README.md`
     * warns about.
     */
    private function importFullRoster(): void
    {
        $this->service->import($this->csvKeeping(['T001', 'T002', 'T003']), $this->scoutYearId, 1);
    }

    /**
     * A copy of the sample export keeping only the given Desk
     * identifiers — the shape a section-filtered export from Desk has.
     *
     * @param string[] $deskIds
     */
    private function csvKeeping(array $deskIds): string
    {
        $lines = file($this->fixturePath, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $header = array_shift($lines);
        $headers = str_getcsv((string) $header, ';', '"', '');
        $tiersIndex = array_search('Tiers', $headers, true);
        $this->assertIsInt($tiersIndex);

        $kept = [$header];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = str_getcsv($line, ';', '"', '');
            if (in_array($fields[$tiersIndex] ?? '', $deskIds, true)) {
                $kept[] = $line;
            }
        }

        $path = sys_get_temp_dir() . '/scoutmagic_barrier_' . bin2hex(random_bytes(8)) . '.csv';
        file_put_contents($path, implode("\n", $kept) . "\n");
        $this->tempFiles[] = $path;

        return $path;
    }

    private function countActiveMembers(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM member_years WHERE scout_year_id = ? AND is_active = 1');
        $stmt->execute([$this->scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    private function countImportJournalRows(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM import_journal');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    private function journalEntries(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM event_log ORDER BY id');

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function lastJournalEntry(): array
    {
        $entries = $this->journalEntries();
        $this->assertNotSame([], $entries);

        return $entries[count($entries) - 1];
    }
}
