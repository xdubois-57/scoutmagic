<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Importer;

use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\ImportedMemberPhotoWriter;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\PhotoIngestionService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\MemberPhotosImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestArchive;
use Tests\UsesProductionEngine;

/**
 * « Photos des membres », end to end on the engine production runs, with
 * the committed archives of `tests/fixtures/data-migration/
 * scoutmagic-member-photos/` — the ones the format document describes and
 * the end-to-end scenario deposits — and the real photo pipeline.
 *
 * On MariaDB/MySQL because what decides « never an overwrite » is the
 * UNIQUE index of `member_photos` inside the item's transaction, and what
 * decides « without duplicates » is the correspondence table's byte
 * comparison.
 */
#[Group('database')]
final class MemberPhotosImportOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/scoutmagic-member-photos/';

    private \PDO $pdo;
    private string $storage;
    private MemberPhotosImporter $importer;
    private RunService $service;
    private \DateTimeImmutable $now;
    private int $adminId;
    private int $memberId;
    private int $year2324;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_photos_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->now = new \DateTimeImmutable('2026-11-11 10:00:00');
        $clock = fn (): \DateTimeImmutable => $this->now;

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");
        // 2023-2024 exists; 2022-2023, the other year of the example, does not (D5).
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2023-2024', '2023-09-01', '2024-08-31')");
        $this->year2324 = (int) $this->pdo->lastInsertId();
        $this->adminId = $this->member('E2E-ADMIN');
        $this->memberId = $this->member('E2E-MEMBER');

        $files = new FileRepository($this->pdo);
        $uploadHandler = new class ($files, $this->storage) extends UploadHandler {
            protected function moveFile(string $from, string $to): bool
            {
                return copy($from, $to);
            }
        };
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $ingestion = new PhotoIngestionService(
            $uploadHandler,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionPhotoService(new SectionPhotoRepository($this->pdo)),
            new SectionPhotoProcessor(),
            new LandscapeImageProcessor(),
            new AgeBranchRepository($this->pdo),
            new UnitLogoService(new UnitLogoProcessor(), $settings, $this->storage . '/logo', $this->storage . '/logo_defaults'),
            new ImageVariantService($files, new ImageVariantProcessor(), $this->storage)
        );
        $this->importer = new MemberPhotosImporter(
            new MemberRepository($this->pdo),
            new ImportedMemberPhotoWriter($ingestion, new MemberPhotoRepository($this->pdo))
        );

        $journal = new JournalService(new JournalRepository($this->pdo));
        $runs = new RunRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32)));
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo), $clock);
        $this->service = new RunService(
            $runs,
            new ImportEngine(
                $this->pdo,
                $runs,
                new ItemMappingRepository($this->pdo),
                $years,
                $uploads,
                new ManifestReader(),
                $journal,
                0.0,
                $clock
            ),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal,
            $clock
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->storage);
    }

    public function testTheExampleArchiveIsAnalysedWithoutWritingAnything(): void
    {
        $run = $this->analysed('exemple.zip');

        $this->assertSame(MemberPhotosImporter::FORMAT, $run->format);
        $this->assertSame(4, $run->analysis['total']);
        $this->assertSame(3, $run->analysis['to_import']);
        // The second photo for E2E-MEMBER in 2023-2024: the first is valid.
        $this->assertSame([MemberPhotosImporter::REASON_EARLIER_LINE => 1], $run->analysis['ignored']);
        $this->assertSame(['2022-2023' => 1], $run->analysis['missing_years']);
        $this->assertSame([['reference' => 'desk E2E-MEMBER · 2023-2024', 'reason' => MemberPhotosImporter::REASON_EARLIER_LINE]], $run->analysis['samples']);
        $this->assertSame(0, $this->rowsIn('member_photos'));
        $this->assertSame(0, $this->rowsIn('files'));
    }

    public function testTheExampleArchiveGivesEachMemberTheirPhotosOfEachYear(): void
    {
        $run = $this->imported('exemple.zip', ['2022-2023']);

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(3, $run->report['imported']);
        $this->assertSame([MemberPhotosImporter::REASON_EARLIER_LINE => 1], $run->report['ignored']);
        $this->assertSame(0, $run->report['errors']);

        $year2223 = (int) $this->pdo->query("SELECT id FROM scout_years WHERE label = '2022-2023'")->fetchColumn();
        $this->assertSame(
            [
                [$this->adminId, $year2223, 'portrait-2022.jpg', 'image/jpeg'],
                [$this->adminId, $this->year2324, null, 'image/png'],
                [$this->memberId, $this->year2324, 'portrait-2023.webp', 'image/webp'],
            ],
            array_map(static fn (array $row): array => [
                (int) $row['member_id'],
                (int) $row['scout_year_id'],
                str_starts_with((string) $row['original_name'], 'portrait-') ? (string) $row['original_name'] : null,
                (string) $row['mime_type'],
            ], $this->photos())
        );
        // Every photo through the pipeline: a member's face is for identified visitors.
        $this->assertSame(['identified'], array_values(array_unique(array_column($this->photos(), 'role_min'))));
        $this->assertSame(3, $this->rowsIn('files'));
        $this->assertSame(3, $this->rowsIn('data_migration_items'));
    }

    /**
     * The scenario the end-to-end test replays: the page closes in the
     * middle, the run is resumed, and nothing is written twice.
     */
    public function testAnInterruptedImportResumesWithoutDuplicates(): void
    {
        $run = $this->analysed('exemple.zip');
        $run = $this->service->confirm($run, ['2022-2023']) ?? $this->fail('Not confirmed.');

        $run = $this->service->advance($this->importer, $run);
        $run = $this->service->advance($this->importer, $run);
        $this->assertSame(Run::RUNNING, $run->state);
        $this->assertSame(2, $this->rowsIn('member_photos'));

        $this->now = $this->now->add(new \DateInterval('PT11M'));
        $run = $this->service->current($this->importer) ?? $this->fail('No run.');
        $this->assertSame(Run::INTERRUPTED, $run->state);

        $run = $this->drive($this->service->resume($run));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(3, $run->report['imported']);
        $this->assertSame(3, $this->rowsIn('member_photos'));
        $this->assertSame(3, $this->rowsIn('files'));
    }

    public function testARerunOfTheSameFileImportsNothing(): void
    {
        $run = $this->imported('exemple.zip', ['2022-2023']);

        $again = $this->drive($this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.'));
        $this->assertSame(3, $again->analysis['present']);
        $this->assertSame(0, $again->analysis['to_import']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(3, $again->report['present']);
        $this->assertSame([MemberPhotosImporter::REASON_EARLIER_LINE => 1], $again->report['ignored']);
        $this->assertSame(3, $this->rowsIn('member_photos'));
        $this->assertSame(3, $this->rowsIn('files'));
    }

    public function testAPhotoAlreadyOnTheSiteIsKeptAndTheFilesOneIgnored(): void
    {
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes) VALUES ('core/member_photos/ours.jpg', 'ours.jpg', 'image/jpeg', 1)");
        $ours = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO member_photos (member_id, scout_year_id, file_id) VALUES (?, ?, ?)')
            ->execute([$this->adminId, $this->year2324, $ours]);

        $run = $this->analysed('exemple.zip');
        $this->assertSame(1, $run->analysis['present']);
        $this->assertSame(2, $run->analysis['to_import']);
        $notes = $this->importer->previewNotes(new AnalysisSummary(4, 2, 1, 0, [], $run->analysis['tags'], []));
        $this->assertSame(
            '1 animé a déjà une photo pour l\'année visée. Elle est conservée, la photo du fichier est ignorée.',
            $notes[0]->text ?? null
        );

        $run = $this->drive($this->service->confirm($run, ['2022-2023']) ?? $this->fail('Not confirmed.'));

        $this->assertSame(2, $run->report['imported']);
        $this->assertSame(1, $run->report['present']);
        $keptQuery = $this->pdo->prepare('SELECT file_id FROM member_photos WHERE member_id = ? AND scout_year_id = ?');
        $keptQuery->execute([$this->adminId, $this->year2324]);
        $kept = $keptQuery->fetchColumn();
        $this->assertSame($ours, (int) $kept);
    }

    public function testAMemberIsFoundByTheDeskIdOfAMergedRecord(): void
    {
        $this->pdo->prepare("UPDATE members SET desk_id = 'NOUVEAU-1' WHERE id = ?")->execute([$this->adminId]);
        $this->pdo->prepare("INSERT INTO member_desk_id_aliases (member_id, desk_id) VALUES (?, 'E2E-ADMIN')")
            ->execute([$this->adminId]);

        $run = $this->imported('exemple.zip', ['2022-2023']);

        $this->assertSame(3, $run->report['imported']);
        $photosOfAdmin = $this->pdo->prepare('SELECT COUNT(*) FROM member_photos WHERE member_id = ?');
        $photosOfAdmin->execute([$this->adminId]);
        $this->assertSame(2, (int) $photosOfAdmin->fetchColumn());
    }

    public function testAnUnknownMemberIsIgnoredAndNeverCreated(): void
    {
        $this->pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$this->memberId]);

        $run = $this->imported('exemple.zip', ['2022-2023']);

        $this->assertSame(2, $run->report['imported']);
        $this->assertSame([MemberPhotosImporter::REASON_UNKNOWN_MEMBER => 2], $run->report['ignored']);
        $this->assertSame(1, $this->rowsIn('members'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM members WHERE desk_id = 'E2E-MEMBER'")->fetchColumn());
    }

    public function testThePhotosOfAYearNotTickedAreIgnored(): void
    {
        $run = $this->imported('exemple.zip', []);

        $this->assertSame(2, $run->report['imported']);
        $this->assertSame(1, $run->report['ignored']['Année scoute non créée'] ?? null);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scout_years WHERE label = '2022-2023'")->fetchColumn());
    }

    public function testEachInvalidLineIsIgnoredWithItsReasonAndTheOthersImported(): void
    {
        $run = $this->analysed('elements-invalides.zip');

        $this->assertSame([
            MemberPhotosImporter::REASON_UNKNOWN_MEMBER => 1,
            MemberPhotosImporter::REASON_UNREADABLE_LINE => 1,
            MemberPhotosImporter::REASON_DESK_ID => 1,
            MemberPhotosImporter::REASON_SCOUT_YEAR => 1,
            MemberPhotosImporter::REASON_SHA256 => 1,
            MemberPhotosImporter::REASON_PATH => 1,
            ImportedMemberPhotoWriter::REASON_TYPE_MISMATCH => 1,
            ImportedMemberPhotoWriter::REASON_UNREADABLE => 1,
            MemberPhotosImporter::REASON_MISSING_IMAGE => 1,
            MemberPhotosImporter::REASON_FINGERPRINT => 1,
        ], $run->analysis['ignored']);
        $this->assertSame(1, $run->analysis['to_import']);
        // D8: the report shows the old site's references, never a name.
        foreach ($run->analysis['samples'] as $sample) {
            $this->assertMatchesRegularExpression('/^(ligne \d+|desk [A-Z0-9-]+ · \d{4}-\d{4})$/', $sample['reference']);
        }

        $run = $this->drive($this->service->confirm($run, ['2022-2023']) ?? $this->fail('Not confirmed.'));

        $this->assertSame(1, $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);
        $this->assertSame([[$this->memberId]], array_map(static fn (array $row): array => [(int) $row['member_id']], $this->photos()));
    }

    public function testABrokenFirstPhotoDoesNotHideAValidSecondOne(): void
    {
        $broken = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12) . 'not the rest of a JPEG';
        $valid = self::jpeg();
        $run = $this->analysedArchive(self::archive([
            [$broken, 'jpg', 'E2E-ADMIN', '2023-2024'],
            [$valid, 'jpg', 'E2E-ADMIN', '2023-2024'],
        ]));

        $this->assertSame(1, $run->analysis['to_import']);
        $this->assertSame([ImportedMemberPhotoWriter::REASON_UNREADABLE => 1], $run->analysis['ignored']);

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(1, $run->report['imported']);
        $this->assertSame(
            'E2E-ADMIN/2023-2024/' . hash('sha256', $valid),
            (string) $this->pdo->query('SELECT source_id FROM data_migration_items')->fetchColumn()
        );
        $this->assertSame(1, $this->rowsIn('member_photos'));
    }

    public function testOneImageMayServeSeveralYears(): void
    {
        $image = self::jpeg();
        $run = $this->imported(self::archive([
            [$image, 'jpg', 'E2E-ADMIN', '2023-2024'],
            [$image, 'jpg', 'E2E-MEMBER', '2023-2024'],
        ]), []);

        $this->assertSame(2, $run->report['imported']);
        $this->assertSame(2, $this->rowsIn('member_photos'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedArchives(): array
    {
        return [
            'wrong fingerprint' => ['invalide-empreinte.zip', 'ne correspond pas au manifeste'],
            'unknown major version' => ['invalide-version.zip', 'version 2.0.0'],
            'extension not accepted' => ['invalide-extension.zip', 'n\'a pas une extension acceptée'],
        ];
    }

    #[DataProvider('refusedArchives')]
    public function testABrokenArchiveIsRefusedWholeBeforeAnyWrite(string $fixture, string $expected): void
    {
        $run = $this->drive($this->deposit(self::FIXTURES . $fixture));

        $this->assertSame(Run::ABANDONED, $run->state);
        $this->assertStringContainsString($expected, (string) $run->rejectionReason);
        $this->assertSame(0, $this->rowsIn('member_photos'));
        $this->assertSame(0, $this->rowsIn('files'));
    }

    public function testTheImportSendsNothingAndTouchesNoOtherPhoto(): void
    {
        $this->imported('exemple.zip', ['2022-2023']);

        foreach (['notifications', 'mail_deferred_messages', 'mail_send_receipts', 'user_account_photos', 'section_staff_photos'] as $table) {
            $this->assertSame(0, $this->rowsIn($table), $table);
        }
    }

    private function member(string $deskId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function photos(): array
    {
        return $this->pdo->query(
            'SELECT mp.member_id, mp.scout_year_id, f.original_name, f.mime_type, f.role_min
             FROM member_photos mp JOIN files f ON f.id = mp.file_id ORDER BY mp.id'
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function rowsIn(string $table): int
    {
        // A table name cannot be bound, so it must be one of these.
        $tables = [
            'member_photos', 'files', 'data_migration_items', 'members', 'scout_years',
            'notifications', 'mail_deferred_messages', 'mail_send_receipts',
            'user_account_photos', 'section_staff_photos',
        ];
        if (!in_array($table, $tables, true)) {
            throw new \InvalidArgumentException('Unexpected table: ' . $table);
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    /**
     * An archive of the format, built for one test.
     *
     * @param list<array{string, string, string, string}> $photos bytes, extension, desk id, year
     */
    private static function archive(array $photos): string
    {
        $files = ['photos.jsonl' => ''];
        foreach ($photos as [$bytes, $extension, $deskId, $year]) {
            $path = 'images/' . hash('sha256', $bytes) . '.' . $extension;
            $files['photos.jsonl'] .= json_encode([
                'desk_id' => $deskId,
                'scout_year' => $year,
                'path' => $path,
                'sha256' => hash('sha256', $bytes),
            ], JSON_UNESCAPED_SLASHES) . "\n";
            $files[$path] = $bytes;
        }

        return TestArchive::build($files, ['format' => MemberPhotosImporter::FORMAT, 'format_version' => '1.0.0']);
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(48, 64);
        imagefill($image, 0, 0, imagecolorallocate($image, 61, 122, 74));
        ob_start();
        imagejpeg($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $years the past years ticked at the confirmation
     */
    private function imported(string $fixtureOrArchive, array $years): Run
    {
        $run = str_contains($fixtureOrArchive, '/')
            ? $this->analysedArchive($fixtureOrArchive)
            : $this->analysed($fixtureOrArchive);

        return $this->drive($this->service->confirm($run, $years) ?? $this->fail('Not confirmed.'));
    }

    private function analysed(string $fixture): Run
    {
        // A copy: the deposit moves its fragment, and the fixture stays.
        $copy = sys_get_temp_dir() . '/dm_photos_fixture_' . bin2hex(random_bytes(6)) . '.zip';
        copy(self::FIXTURES . $fixture, $copy);

        return $this->analysedArchive($copy);
    }

    private function analysedArchive(string $zip): Run
    {
        $run = $this->drive($this->deposit($zip));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function deposit(string $zip): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_photos_chunk_' . bin2hex(random_bytes(6));
        copy($zip, $chunk);

        $result = $this->service->receiveChunk(
            $this->importer,
            bin2hex(random_bytes(16)),
            'test-session',
            0,
            $chunk,
            true,
            'photos-membres.zip',
            null
        );
        @unlink($chunk);

        return $result['run'] ?? $this->fail('The last fragment created no run.');
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 200 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
