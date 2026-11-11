<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Config\ScoutYearService;
use Core\File\ChunkedUploadStore;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceImportWriter;
use Modules\Camps\Service\PlaceService;
use Modules\DataMigration\Importer\CampPlacesImporter;
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
use Tests\UsesProductionEngine;

/**
 * « Endroits de camp » end to end, on the engine production runs: the
 * example archive of the format document deposited, analysed, confirmed
 * and executed through the module's shared machinery, with the camps
 * module's real writer behind it.
 *
 * On MariaDB/MySQL because the run's per-item transaction has to carry the
 * place, its history and its correspondence row together, and because the
 * correspondence key is compared byte for byte by the engine.
 */
#[Group('database')]
final class CampPlacesImportOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/scoutmagic-camp-places/';

    private \PDO $pdo;
    private string $storage;
    private CampPlacesImporter $importer;
    private RunService $service;
    private PlaceRepository $places;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_camps_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runs = new RunRepository($this->pdo, $encryption);
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo));
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
                0.0
            ),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal
        );

        $this->places = new PlaceRepository($this->pdo);
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->importer = new CampPlacesImporter(new PlaceImportWriter(
            $this->places,
            new PlaceService($this->places, $audit),
            new DuplicatePlaceDetector($this->places),
            $audit
        ));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->storage);
    }

    public function testTheExampleArchiveCreatesItsPlacesOnAnEmptySite(): void
    {
        $run = $this->imported('endroits-de-camp.zip');

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(4, $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);

        $byName = $this->placesByName();
        $this->assertSame(['Domaine des Trois Sources', 'Ferme du Bois-Haut', 'Le pré du Moulin', 'Prairie Lambert'], array_keys($byName));

        $farm = $byName['Ferme du Bois-Haut'];
        $this->assertSame('Chemin du Bois-Haut 12', $farm['address']);
        $this->assertSame('5360', $farm['postal_code']);
        $this->assertSame('https://ferme-du-bois-haut.example', $farm['website_url']);
        $this->assertSame('50.341205', (string) $farm['latitude']);
        $this->assertSame('5.150118', (string) $farm['longitude']);
        $this->assertSame(1, (int) $farm['coordinates_are_manual']);
        $this->assertSame(0, (int) $farm['is_archived']);
        $this->assertNull($farm['geocoded_at'], 'The import geocoded a place.');

        $this->assertSame(1, (int) $byName['Domaine des Trois Sources']['is_archived']);
        $this->assertSame(0, (int) $byName['Le pré du Moulin']['coordinates_are_manual']);

        $this->assertSame(
            [['place', 'camp_place']],
            $this->pdo->query('SELECT DISTINCT source_kind, target_type FROM data_migration_items')->fetchAll(\PDO::FETCH_NUM)
        );
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM data_migration_items')->fetchColumn());
    }

    public function testExistingPlacesAreRecognisedArchivedOnesIncludedAndNeverTouched(): void
    {
        // The site already knows two of them: one archived, under the same
        // name in the same town (certain), and one under the same name in
        // another town (only possible).
        $archived = $this->places->create('Domaine des Trois Sources', null, '6690', 'Vielsalm', null, null);
        $this->places->archive($archived, true);
        $elsewhere = $this->places->create('Prairie Lambert', null, '5575', 'Gedinne', null, null);
        $before = [$this->places->findById($archived), $this->places->findById($elsewhere)];

        $run = $this->analysed('endroits-de-camp.zip');
        $this->assertSame(2, $run->analysis['to_import']);
        $this->assertSame(1, $run->analysis['present']);
        $this->assertSame(1, $run->analysis['ambiguous']);
        $this->assertSame(
            [['reference' => 'p-0002 · Prairie Lambert, Leignon', 'reason' => 'Ressemble à « Prairie Lambert », Gedinne']],
            $run->analysis['samples']
        );

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(2, $run->report['imported']);
        $this->assertSame(1, $run->report['present']);
        $this->assertSame(1, $run->report['ignored']['Ressemble à « Prairie Lambert », Gedinne']);
        $this->assertSame(4, $this->placeCount(), 'An ambiguous or present place was created.');
        $this->assertEquals($before, [$this->places->findById($archived), $this->places->findById($elsewhere)]);
    }

    public function testARerunCreatesNothingTwice(): void
    {
        $run = $this->imported('endroits-de-camp.zip');
        $this->assertSame(4, $this->placeCount());

        $again = $this->drive($this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.'));
        $this->assertSame(4, $again->analysis['present']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(4, $again->report['present']);
        $this->assertSame(4, $this->placeCount());
    }

    public function testAnImportedPlaceThatWasEditedSinceIsStillNotRecreated(): void
    {
        $run = $this->imported('endroits-de-camp.zip');
        $this->pdo->exec("UPDATE camp_places SET name = 'Ferme du Bois-Haut (Hamois)' WHERE name = 'Ferme du Bois-Haut'");

        $again = $this->drive($this->service->restart($this->importer, $run, null) ?? $this->fail('No restart.'));
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        // The correspondence table answers before any name comparison.
        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(4, $this->placeCount());
    }

    public function testInvalidLinesAreIgnoredWithTheirReasonAndTheOthersImported(): void
    {
        $run = $this->analysed('elements-invalides.zip');

        $this->assertSame(7, $run->analysis['total']);
        $this->assertSame(3, $run->analysis['to_import']);
        $this->assertSame(3, $run->analysis['tags']['unrecognised']);
        $this->assertSame(1, $run->analysis['ignored']['Sans nom']);
        $this->assertSame(1, $run->analysis['ignored']['Ligne illisible']);
        $this->assertSame(1, $run->analysis['ignored']['Nom de plus de 150 caractères']);
        $this->assertSame(1, $run->analysis['ignored']['Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères']);

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(3, $run->report['imported']);
        $byName = $this->placesByName();
        $this->assertNull($byName['Coordonnées incomplètes']['latitude']);
        $this->assertNull($byName['Coordonnées hors limites']['latitude']);
        $this->assertNull($byName['Coordonnées hors limites']['longitude']);
        $this->assertNull($byName['Site web invalide']['website_url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedArchives(): array
    {
        return [
            'unknown major version' => ['version-majeure-inconnue.zip'],
            'wrong hash' => ['empreinte-fausse.zip'],
            'another format' => ['autre-format.zip'],
            'forbidden extension' => ['extension-refusee.zip'],
            'no places.jsonl' => ['fichier-absent.zip'],
        ];
    }

    #[DataProvider('rejectedArchives')]
    public function testAnInvalidArchiveIsRefusedBeforeAnythingIsWritten(string $fixture): void
    {
        $run = $this->drive($this->deposit($fixture));

        $this->assertSame(Run::ABANDONED, $run->state);
        $this->assertNotNull($run->rejectionReason);
        $this->assertSame(0, $this->placeCount());
    }

    public function testTheImportSendsNothingAndSchedulesNothing(): void
    {
        $tables = ['notifications', 'scheduled_actions', 'mail_deferred_messages', 'mail_lane_entries'];
        $before = array_map(fn (string $table): int => $this->rowCount($table), $tables);

        $this->imported('endroits-de-camp.zip');

        $this->assertSame($before, array_map(fn (string $table): int => $this->rowCount($table), $tables));
    }

    private function imported(string $fixture): Run
    {
        return $this->drive($this->service->confirm($this->analysed($fixture), []) ?? $this->fail('Not confirmed.'));
    }

    private function analysed(string $fixture): Run
    {
        $run = $this->drive($this->deposit($fixture));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function deposit(string $fixture): Run
    {
        $chunk = $this->storage . '/chunk_' . bin2hex(random_bytes(6));
        copy(self::FIXTURES . $fixture, $chunk);

        $result = $this->service->receiveChunk(
            $this->importer,
            bin2hex(random_bytes(16)),
            'test-session',
            0,
            $chunk,
            true,
            $fixture,
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function placesByName(): array
    {
        $rows = [];
        foreach ($this->pdo->query('SELECT * FROM camp_places ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function placeCount(): int
    {
        return $this->rowCount('camp_places');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
