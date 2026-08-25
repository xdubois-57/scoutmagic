<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportRetentionService;
use Core\Import\RosterSnapshotRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The retention of Desk imports: two scout years by default, whole
 * seasons only, and the row, the file and the snapshot going together.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportRetentionServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private ImportRetentionService $service;
    private ImportJournalRepository $imports;
    private RosterSnapshotRepository $snapshots;
    private EncryptedFileStorageService $storage;
    private SettingService $settings;
    /** @var array<string, int> */
    private array $years = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_retention_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $fileRepository = new FileRepository($this->pdo);

        $this->imports = new ImportJournalRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $this->storage = new EncryptedFileStorageService($fileRepository, $encryption, $this->storagePath);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        // Oldest first, so the newest ends up "current".
        foreach (['2022-2023', '2023-2024', '2024-2025', '2025-2026'] as $label) {
            $start = substr($label, 0, 4) . '-09-01';
            $end = substr($label, 5, 4) . '-08-31';
            $stmt = $this->pdo->prepare(
                'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$label, $start, $end, $label === '2025-2026' ? 1 : 0]);
            $this->years[$label] = (int) $this->pdo->lastInsertId();
        }

        $this->service = new ImportRetentionService(
            $this->pdo,
            $this->imports,
            $this->snapshots,
            $fileRepository,
            new ScoutYearService($this->pdo),
            $this->settings,
            new JournalService(new JournalRepository($this->pdo)),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/imports/*') ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->storagePath . '/imports');
        @rmdir($this->storagePath);
    }

    public function testTheDefaultKeepsTheCurrentYearAndThePreviousOne(): void
    {
        $this->assertSame(ImportRetentionService::DEFAULT_YEARS, $this->service->retentionYears());

        $beyond = $this->service->yearsBeyondRetention();

        $this->assertSame([$this->years['2023-2024'], $this->years['2022-2023']], $beyond);
    }

    public function testTheRetentionIsConfigurable(): void
    {
        $this->settings->register(ImportRetentionService::SETTING_KEY, '2', 'number', 'Conservation', 'Description');
        $this->settings->set(ImportRetentionService::SETTING_KEY, '3');

        $this->assertSame(3, $this->service->retentionYears());
        $this->assertSame([$this->years['2022-2023']], $this->service->yearsBeyondRetention());
    }

    public function testARetentionOfZeroIsNeverHonouredBelowOneYear(): void
    {
        $this->settings->register(ImportRetentionService::SETTING_KEY, '2', 'number', 'Conservation', 'Description');
        $this->settings->set(ImportRetentionService::SETTING_KEY, '0');

        // The current year's snapshots are what the invoice verification
        // reads all season; losing them mid-season is not a retention
        // choice anybody can meaningfully make.
        $this->assertSame(1, $this->service->retentionYears());
        $this->assertNotContains($this->years['2025-2026'], $this->service->yearsBeyondRetention());
    }

    public function testAYearPreparedInAdvanceIsNeverPurged(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)');
        $stmt->execute(['2026-2027', '2026-09-01', '2027-08-31']);
        $futureYearId = (int) $this->pdo->lastInsertId();

        $beyond = $this->service->yearsBeyondRetention();

        $this->assertNotContains($futureYearId, $beyond);
        // And the window is still two real seasons wide, not one plus the future.
        $this->assertNotContains($this->years['2024-2025'], $beyond);
        $this->assertContains($this->years['2023-2024'], $beyond);
    }

    public function testAPurgeTakesTheRowTheFileAndTheSnapshotTogether(): void
    {
        [$oldImportId, $oldFileId, $oldPath] = $this->seedImport('2022-2023');
        [$keptImportId, $keptFileId, $keptPath] = $this->seedImport('2025-2026');

        $purged = $this->service->purge();

        $this->assertSame(1, $purged);

        // Gone: row, file record, blob, snapshot and its members.
        $this->assertNull($this->imports->findById($oldImportId));
        $this->assertNull((new FileRepository($this->pdo))->findById($oldFileId));
        $this->assertFileDoesNotExist($oldPath);
        $this->assertNull($this->snapshots->findByImport($oldImportId));
        $this->assertSame(0, $this->countSnapshotMembers($this->years['2022-2023']));

        // Untouched: everything inside the window.
        $this->assertNotNull($this->imports->findById($keptImportId));
        $this->assertNotNull((new FileRepository($this->pdo))->findById($keptFileId));
        $this->assertFileExists($keptPath);
        $this->assertNotNull($this->snapshots->findByImport($keptImportId));
    }

    public function testAPurgeWithNothingToPurgeDoesNothingAndSaysNothing(): void
    {
        $this->seedImport('2025-2026');
        $this->seedImport('2024-2025');

        $this->assertSame(0, $this->service->purge());
        $this->assertSame([], $this->journalEntries());
    }

    public function testThePurgeIsJournaledWithCountersOnly(): void
    {
        $this->seedImport('2022-2023');
        $this->service->purge();

        $entries = $this->journalEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('desk_imports_purged', $entries[0]['event_type']);
        $context = (string) $entries[0]['context'];
        $this->assertStringContainsString('"import_count":1', $context);
        $this->assertStringNotContainsString('Dupont', $context);
        $this->assertStringNotContainsString('.csv', $context);
    }

    public function testEveryImportOfAPurgedSeasonGoes(): void
    {
        $this->seedImport('2022-2023');
        $this->seedImport('2022-2023');
        $this->seedImport('2023-2024');

        $this->assertSame(3, $this->service->purge());
        $this->assertSame(0, $this->imports->countForYear($this->years['2022-2023']));
        $this->assertSame(0, $this->imports->countForYear($this->years['2023-2024']));
    }

    /* ------------------------------------------------------------------ */

    /**
     * One import of $yearLabel, with a kept file and a snapshot carrying
     * one member — the whole dossier the purge has to take at once.
     *
     * @return array{int, int, string} import id, file id, absolute blob path
     */
    private function seedImport(string $yearLabel): array
    {
        $scoutYearId = $this->years[$yearLabel];

        $fileId = $this->storage->store(
            "Nom;Prenom\nDupont;Jean\n",
            'text/csv',
            'desk_export.csv',
            'imports',
            'admin'
        );
        $importId = $this->imports->create($scoutYearId, 1, 2, 1, 0, $fileId);
        $this->storage->assignOwner($fileId, 'desk_import', $importId);

        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['T' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshots (scout_year_id, import_journal_id, taken_at, member_count) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$scoutYearId, $importId, '2026-01-01 10:00:00']);
        $snapshotId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO fees_roster_snapshot_members (snapshot_id, member_id) VALUES (?, ?)'
        );
        $stmt->execute([$snapshotId, $memberId]);

        $file = (new FileRepository($this->pdo))->findById($fileId);
        $this->assertNotNull($file);

        return [$importId, $fileId, $this->storagePath . '/' . $file->relativePath];
    }

    private function countSnapshotMembers(int $scoutYearId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM fees_roster_snapshot_members
             WHERE snapshot_id IN (SELECT id FROM fees_roster_snapshots WHERE scout_year_id = ?)'
        );
        $stmt->execute([$scoutYearId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> */
    private function journalEntries(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'desk_imports_purged' ORDER BY id");

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
