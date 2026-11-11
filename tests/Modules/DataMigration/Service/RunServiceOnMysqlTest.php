<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\File\UploadException;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadPurger;
use Modules\DataMigration\Service\UploadStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\TestArchive;
use Tests\Modules\DataMigration\Support\TestImporter;
use Tests\UsesProductionEngine;

/**
 * The shared machinery of the data migration module, end to end, on the
 * engine production runs: deposit, verification, analysis, confirmation,
 * execution in slices, interruption and resumption, re-run, rejection of a
 * malicious or inconsistent archive, and the purge.
 *
 * On MariaDB/MySQL rather than SQLite because three things here are the
 * engine's to decide: the correspondence table's unique key over VARBINARY
 * columns (byte comparison: `A-1` is not `a-1`, `007` is not `7`), the
 * named lock a slice takes, and the per-item transaction that must roll
 * back what an import wrote when it fails.
 *
 * The slice budget is zero seconds, so every slice handles exactly one
 * step: interruption between any two items is then something a test can
 * ask for rather than hope for.
 */
#[Group('database')]
final class RunServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private string $storage;
    private TestImporter $importer;
    private RunRepository $runs;
    private RunService $service;
    private UploadStorage $uploads;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_storage_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->now = new \DateTimeImmutable();

        $this->importer = new TestImporter($this->pdo);
        $this->pdo->exec('DELETE FROM test_written');

        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $clock = fn (): \DateTimeImmutable => $this->now;
        $this->runs = new RunRepository($this->pdo, $encryption);
        $this->uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo), $clock);

        $this->service = new RunService(
            $this->runs,
            new ImportEngine(
                $this->pdo,
                $this->runs,
                new ItemMappingRepository($this->pdo),
                $years,
                $this->uploads,
                new ManifestReader(),
                $journal,
                0.0,
                $clock
            ),
            $years,
            $this->uploads,
            new ChunkedUploadStore($this->storage),
            $journal,
            $clock
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->storage);
    }

    public function testAValidArchiveIsAnalysedWithoutWritingAnything(): void
    {
        $run = $this->deposit(TestArchive::items([
            ['id' => '1', 'name' => 'Un'],
            ['id' => '2', 'name' => ''],
            'pas du JSON',
            ['id' => 3, 'name' => 'identifiant numérique'],
            ['id' => '4', 'name' => 'Quatre', 'ambiguous' => true],
        ]));

        $this->assertSame(Run::UPLOADED, $run->state);
        $this->assertSame('ancien-site', $run->installationId);
        $this->assertSame('1.2.0', $run->formatVersion);

        $run = $this->drive($run);

        $this->assertSame(Run::ANALYSED, $run->state);
        $this->assertSame(5, $run->analysis['total']);
        $this->assertSame(1, $run->analysis['to_import']);
        $this->assertSame(1, $run->analysis['ambiguous']);
        $this->assertSame(1, $run->analysis['ignored']['Sans nom']);
        $this->assertSame(1, $run->analysis['ignored']['Ligne illisible']);
        $this->assertSame(
            1,
            $run->analysis['ignored']['Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères']
        );
        $this->assertSame(1, $run->analysis['tags']['named']);
        $this->assertSame(0, $this->writtenCount(), 'The analysis wrote something.');
        $this->assertSame(0, $this->mappingCount());
    }

    public function testTheExecutionWritesEachItemOnceWithItsCorrespondence(): void
    {
        $run = $this->analysed(TestArchive::items([
            ['id' => '1', 'name' => 'Un'],
            ['id' => '2', 'name' => 'Deux'],
            ['id' => '2', 'kind' => 'form', 'name' => 'Formulaire de deux'],
        ]));

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(3, $run->report['imported']);
        $this->assertSame(3, $this->writtenCount());
        // An article and its form may share an id: the kind is part of the key.
        $this->assertSame(3, $this->mappingCount());
    }

    /**
     * The scenario the module exists for: the page closes in the middle,
     * the run is reopened and resumed, and nothing is written twice.
     */
    public function testAnInterruptedImportResumesWithoutDuplicates(): void
    {
        $run = $this->analysed(TestArchive::items(array_map(
            static fn (int $i): array => ['id' => 'e-' . $i, 'name' => 'Élément ' . $i],
            range(1, 6)
        )));
        $run = $this->service->confirm($run, []) ?? $this->fail('Not confirmed.');

        $run = $this->service->advance($this->importer, $run);
        $run = $this->service->advance($this->importer, $run);
        $this->assertSame(Run::RUNNING, $run->state);
        $this->assertSame(2, $this->writtenCount());

        // The page closed: eleven minutes without a slice.
        $this->now = $this->now->add(new \DateInterval('PT11M'));
        $run = $this->service->current($this->importer) ?? $this->fail('No run.');
        $this->assertSame(Run::INTERRUPTED, $run->state);

        // A poll still in flight does not restart it.
        $this->assertSame(Run::INTERRUPTED, $this->service->advance($this->importer, $run)->state);

        $run = $this->drive($this->service->resume($run));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(6, $run->report['imported']);
        $this->assertSame(6, $this->writtenCount());
        $this->assertSame(6, $this->mappingCount());
    }

    public function testARerunOfTheSameFileFindsEverythingAlreadyPresent(): void
    {
        $run = $this->analysed(TestArchive::items([['id' => '1', 'name' => 'Un'], ['id' => '2', 'name' => 'Deux']]));
        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(2, $run->report['imported']);

        $again = $this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.');
        $again = $this->drive($again);
        $this->assertSame(2, $again->analysis['present']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(2, $again->report['present']);
        $this->assertSame(2, $this->writtenCount());
    }

    /**
     * Each item is its own transaction: what the import wrote before it
     * failed is rolled back with the correspondence it never got, the
     * failure is counted, and the run goes on.
     */
    public function testATechnicalErrorRollsBackItsItemAndTheImportCarriesOn(): void
    {
        $this->importer->failingIds = ['2'];
        $run = $this->analysed(TestArchive::items([
            ['id' => '1', 'name' => 'Un'],
            ['id' => '2', 'name' => 'Deux'],
            ['id' => '3', 'name' => 'Trois'],
        ]));

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(2, $run->report['imported']);
        $this->assertSame(1, $run->report['errors']);
        $this->assertSame('2', $run->report['error_samples'][0]['reference']);
        $this->assertSame(2, $this->writtenCount(), 'The failed item left a partial write behind.');

        // Only the failed item is tried again.
        $this->importer->failingIds = [];
        $again = $this->drive($this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.'));
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(1, $again->report['imported']);
        $this->assertSame(2, $again->report['present']);
    }

    public function testSourceIdentifiersAreComparedByteForByte(): void
    {
        $run = $this->analysed(TestArchive::items([
            ['id' => 'A-1', 'name' => 'Majuscule'],
            ['id' => 'a-1', 'name' => 'Minuscule'],
            ['id' => '007', 'name' => 'Zéros'],
            ['id' => '7', 'name' => 'Sans zéros'],
        ]));

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(4, $run->report['imported']);
        $this->assertSame(4, $this->mappingCount());
    }

    /**
     * D5: missing PAST years are offered; only the ticked ones are created;
     * the items of the others are ignored; the current and future years are
     * never offered.
     */
    public function testOnlyTheTickedPastYearsAreCreated(): void
    {
        $current = ScoutYearService::labelForDate($this->now);
        $start = (int) substr($current, 0, 4);
        $past = sprintf('%d-%d', $start - 8, $start - 7);
        $otherPast = sprintf('%d-%d', $start - 9, $start - 8);
        $future = sprintf('%d-%d', $start + 2, $start + 3);

        $run = $this->analysed(TestArchive::items([
            ['id' => '1', 'name' => 'Un', 'year' => $past],
            ['id' => '2', 'name' => 'Deux', 'year' => $otherPast],
            ['id' => '3', 'name' => 'Trois', 'year' => $future],
        ]));

        $this->assertSame([$past => 1, $otherPast => 1], $run->analysis['missing_years']);
        $this->assertSame(1, $run->analysis['ignored']['Année scoute en cours ou à venir, absente du site']);

        $run = $this->drive($this->service->confirm($run, [$past, $future]) ?? $this->fail('Not confirmed.'));

        $this->assertSame([$past], $run->chosenYears);
        $this->assertNotNull((new ScoutYearService($this->pdo))->findByLabel($past));
        $this->assertNull((new ScoutYearService($this->pdo))->findByLabel($otherPast));
        $this->assertNull((new ScoutYearService($this->pdo))->findByLabel($future));
        $this->assertSame(1, $run->report['imported']);
        $this->assertSame(1, $run->report['ignored']['Année scoute non créée']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedArchives(): array
    {
        return [
            'wrong fingerprint' => ['fingerprint', 'empreinte SHA-256'],
            'unknown major version' => ['major', 'version 2.0.0'],
            'incomplete version' => ['semver', 'version complète'],
            'numeric installation id' => ['numeric', 'source.installation_id'],
            'another format' => ['format', 'attend le format'],
            'member missing from the inventory' => ['unlisted', 'ne cite pas'],
            'inventory naming an absent member' => ['absent', 'absent de l\'archive'],
            'path traversal' => ['traversal', 'chemin refusé'],
            'symbolic link' => ['symlink', 'lien symbolique'],
            'decompression bomb' => ['bomb', 'taille'],
            'no manifest' => ['no-manifest', 'manifest.json'],
        ];
    }

    /**
     * A malicious or inconsistent archive is rejected as a whole, before
     * anything is written, with its reason; and its file is deleted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedArchives')]
    public function testAMalformedArchiveIsRejectedBeforeAnyWrite(string $case, string $expected): void
    {
        $run = $this->drive($this->deposit(self::malformed($case)));

        $this->assertSame(Run::ABANDONED, $run->state);
        $this->assertStringContainsString($expected, (string) $run->rejectionReason);
        $this->assertNull($run->filePath);
        $this->assertSame([], glob($this->storage . '/' . UploadStorage::DIR . '/*') ?: []);
        $this->assertSame(0, $this->writtenCount());
    }

    public function testAbandoningKeepsWhatIsImportedAndDeletesTheFile(): void
    {
        $run = $this->analysed(TestArchive::items([['id' => '1', 'name' => 'Un'], ['id' => '2', 'name' => 'Deux']]));
        $run = $this->service->advance($this->importer, $this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));
        $run = $this->service->pause($run);
        $file = $this->uploads->absolute((string) $run->filePath);
        $this->assertFileExists($file);

        $run = $this->service->abandon($run);

        $this->assertSame(Run::ABANDONED, $run->state);
        $this->assertFileDoesNotExist($file);
        $this->assertSame(1, $this->writtenCount());
        $this->assertSame(1, $this->mappingCount());
    }

    /**
     * The purge: 24 hours after the last movement, whatever the idle state;
     * never the file of a run that is moving.
     */
    public function testThePurgeDeletesIdleFilesAndNeverAMovingOne(): void
    {
        $idle = $this->analysed(TestArchive::items([['id' => '1', 'name' => 'Un']]));
        $idleFile = $this->uploads->absolute((string) $idle->filePath);

        $this->now = $this->now->add(new \DateInterval('PT23H'));
        $other = new TestImporter($this->pdo, 'autre');
        $moving = $this->depositFor($other, TestArchive::items([['id' => '9', 'name' => 'Neuf']]));
        $movingFile = $this->uploads->absolute((string) $moving->filePath);

        $this->now = $this->now->add(new \DateInterval('PT2H'));
        $result = (new UploadPurger($this->runs, $this->uploads, fn (): \DateTimeImmutable => $this->now))->purge();

        $this->assertSame(1, $result['files']);
        $this->assertFileDoesNotExist($idleFile);
        $this->assertFileExists($movingFile);
        $purged = $this->runs->find($idle->id) ?? $this->fail('Run vanished.');
        $this->assertSame(Run::ABANDONED, $purged->state);
        $this->assertNull($purged->filePath);
    }

    public function testANewDepositIsRefusedWhileAnImportOfTheSameKindIsMoving(): void
    {
        $run = $this->analysed(TestArchive::items([['id' => '1', 'name' => 'Un'], ['id' => '2', 'name' => 'Deux']]));
        $this->service->advance($this->importer, $this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->expectException(UploadException::class);
        $this->deposit(TestArchive::items([['id' => '3', 'name' => 'Trois']]));
    }

    // --- harness --------------------------------------------------------

    private function deposit(string $zip): Run
    {
        return $this->depositFor($this->importer, $zip);
    }

    private function depositFor(TestImporter $importer, string $zip): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_chunk_' . bin2hex(random_bytes(6));
        rename($zip, $chunk);

        $result = $this->service->receiveChunk(
            $importer,
            bin2hex(random_bytes(16)),
            'test-session',
            0,
            $chunk,
            true,
            'export.zip',
            null
        );
        @unlink($chunk);

        return $result['run'] ?? $this->fail('The last fragment created no run.');
    }

    private function analysed(string $zip): Run
    {
        $run = $this->drive($this->deposit($zip));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 200 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }

    private function writtenCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM test_written')->fetchColumn();
    }

    private function mappingCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM data_migration_items')->fetchColumn();
    }

    private static function malformed(string $case): string
    {
        $items = ['items.jsonl' => '{"id":"1","name":"Un"}' . "\n"];

        switch ($case) {
            case 'fingerprint':
                $path = TestArchive::build($items);
                $zip = new \ZipArchive();
                $zip->open($path);
                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
                $manifest['files'][0]['sha256'] = str_repeat('0', 64);
                $zip->addFromString('manifest.json', (string) json_encode($manifest));
                $zip->close();

                return $path;
            case 'major':
                return TestArchive::build($items, ['format_version' => '2.0.0']);
            case 'semver':
                return TestArchive::build($items, ['format_version' => '1.0']);
            case 'numeric':
                return TestArchive::build($items, ['source' => ['installation_id' => 12]]);
            case 'format':
                return TestArchive::build($items, ['format' => 'un-autre-format']);
            case 'unlisted':
                return TestArchive::build($items, [], ['extra.json' => '{}']);
            case 'absent':
                return TestArchive::build($items, ['files' => [
                    ['path' => 'items.jsonl', 'sha256' => hash('sha256', $items['items.jsonl'])],
                    ['path' => 'ailleurs.jsonl', 'sha256' => str_repeat('a', 64)],
                ]]);
            case 'traversal':
                return self::rawZip(static function (\ZipArchive $zip): void {
                    $zip->addFromString('../../config/app.json', '{}');
                });
            case 'symlink':
                return self::rawZip(static function (\ZipArchive $zip): void {
                    $zip->addFromString('lien.json', '/etc/passwd');
                    $zip->setExternalAttributesName('lien.json', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
                });
            case 'bomb':
                // A member whose declared size was forged down to 100
                // bytes: only the bounded READ can catch it.
                $bytes = str_repeat("\0", 3 * 1048576);
                $path = TestArchive::build(['items.jsonl' => $bytes]);
                $raw = (string) file_get_contents($path);
                $local = strpos($raw, "PK\x03\x04");
                $central = strpos($raw, "PK\x01\x02");
                $raw = substr_replace($raw, pack('V', 100), (int) $local + 22, 4);
                $raw = substr_replace($raw, pack('V', 100), (int) $central + 24, 4);
                file_put_contents($path, $raw);

                return $path;
            default:
                return self::rawZip(static function (\ZipArchive $zip): void {
                    $zip->addFromString('items.jsonl', '{}');
                });
        }
    }

    /**
     * @param callable(\ZipArchive): void $fill
     */
    private static function rawZip(callable $fill): string
    {
        $path = sys_get_temp_dir() . '/dm_raw_' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $fill($zip);
        $zip->close();

        return $path;
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }
}
