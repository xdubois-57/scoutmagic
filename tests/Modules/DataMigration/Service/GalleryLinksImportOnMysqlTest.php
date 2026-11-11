<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\ChunkedUploadStore;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\LinkPreview;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\DataMigration\Importer\GalleryLinksImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\SectionByDeskCode;
use Modules\DataMigration\Service\UploadStorage;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Service\ExternalAlbumImportService;
use Modules\Gallery\Service\OgImageStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\DataMigration\Support\FakeLinkPreviewFetcher;
use Tests\UsesProductionEngine;

/**
 * « Galeries photo » end to end, on the engine production runs, with the
 * example archive of the format's documentation and its invalid
 * companions (`tests/fixtures/data-migration/gallery-links/`), the real
 * gallery writer, and no network: the album pages are answered by a fake
 * fetcher.
 *
 * On MariaDB/MySQL because the address lookup is a `LIKE` the column's
 * collation decides (case-insensitive there, which is what lets an address
 * typed `HTTPS://Photos.Example.org` be found), and because the shared
 * machinery's per-item transaction and named lock are the engine's.
 */
#[Group('database')]
final class GalleryLinksImportOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/gallery-links/';

    private \PDO $pdo;
    private string $storage;
    private FakeLinkPreviewFetcher $pages;
    private GalleryLinksImporter $importer;
    private RunService $service;
    private int $adminId;
    /** @var array<string, int> */
    private array $sections = [];

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_gallery_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffré', str_repeat('a', 64)]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('EX-BR', 'Branche d''exemple', 1)");
        $branch = (int) $this->pdo->lastInsertId();
        foreach (['EX-LOUV' => 'Meute d\'exemple', 'EX-ECL' => 'Troupe d\'exemple'] as $code => $name) {
            $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)')->execute([$branch, $code, $name]);
            $this->sections[$code] = (int) $this->pdo->lastInsertId();
        }
        // 2019-2020 exists; 2018-2019, which the example also uses, does not.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2019-2020', '2019-09-01', '2020-08-31')");

        $this->pages = new FakeLinkPreviewFetcher([
            'https://photos.example.org/albums/camp-eclaireurs-2019' => new LinkPreview('Grand camp des éclaireurs', 'Quinze jours en Ardenne', self::png()),
            'https://photos.example.org/albums/journee-de-rentree-2018' => new LinkPreview('Rentrée 2018', null, null),
        ]);
        $this->service = $this->runService(0.0);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->storage);
    }

    public function testTheExampleArchiveIsImportedWithoutNotifyingAnyone(): void
    {
        $run = $this->drive($this->deposit('galeries-exemple.zip'));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);
        $this->assertSame([], $this->pages->fetched, 'The analysis read a remote page.');
        $this->assertSame(5, $run->analysis['total']);
        $this->assertSame(4, $run->analysis['to_import']);
        $this->assertSame(['2018-2019' => 3], $run->analysis['missing_years']);
        $this->assertSame(['Section sans correspondance' => 1], $run->analysis['ignored']);
        $this->assertSame(1, $run->analysis['tags'][GalleryLinksImporter::TAG_TITLE_FROM_PAGE]);

        $run = $this->drive($this->service->confirm($run, ['2018-2019']) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(4, $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);
        $this->assertSame(['Section sans correspondance' => 1], $run->report['ignored']);
        $this->assertSame('album-0005', $run->report['samples'][0]['reference']);

        $albums = $this->albums();
        $this->assertCount(4, $albums);
        $camp = $albums['https://photos.example.org/albums/camp-eclaireurs-2019'];
        $this->assertSame('Grand camp des éclaireurs', $camp['title'], 'A title-less album takes its page\'s.');
        $this->assertSame($this->sections['EX-ECL'], (int) $camp['section_id']);
        $this->assertSame('2018-2019', $this->yearLabel((int) $camp['scout_year_id']), 'The year the date falls in, created on request.');
        $this->assertNotNull($camp['og_image_file_id'], 'The page\'s image is kept as the album\'s own file.');
        $this->assertSame('identified', $this->pdo->query('SELECT role_min FROM files WHERE id = ' . (int) $camp['og_image_file_id'])->fetchColumn());

        $rentree = $albums['https://photos.example.org/albums/journee-de-rentree-2018'];
        $this->assertSame('Journée de rentrée', $rentree['title'], 'The file\'s title wins.');
        $this->assertSame('Rentrée 2018', $rentree['og_title']);
        $this->assertNull($rentree['section_id']);

        $souper = $albums['https://photos.example.org/albums/souper-d-unite-2019'];
        $this->assertSame('2019-2020', $this->yearLabel((int) $souper['scout_year_id']));
        $this->assertNull($souper['og_title'], 'An unreadable page leaves the file\'s data alone.');

        foreach ($albums as $album) {
            $this->assertSame('external', $album['type']);
            $this->assertSame($this->adminId, (int) $album['created_by']);
            $this->assertNull($album['owner_type']);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
    }

    public function testARerunCreatesNothingTwiceAndReadsNoPageAgain(): void
    {
        $run = $this->drive($this->deposit('galeries-exemple.zip'));
        $run = $this->drive($this->service->confirm($run, ['2018-2019']) ?? $this->fail('Not confirmed.'));
        $this->pages->fetched = [];

        $again = $this->drive($this->service->restart($this->importer, $run, $this->adminId) ?? $this->fail('No restart.'));
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(4, $again->report['present']);
        $this->assertCount(4, $this->albums());
        $this->assertSame([], $this->pages->fetched);
    }

    /**
     * An address the gallery already has — typed differently by a chief —
     * is « déjà présent », whoever created it, and is never touched.
     */
    public function testAnAlbumAChiefAlreadyLinkedIsPresent(): void
    {
        $this->pdo->prepare(
            "INSERT INTO gallery_albums (type, title, album_date, scout_year_id, external_url, created_by, created_at)
             VALUES ('external', 'Souper (ajouté à la main)', '2019-11-09', (SELECT id FROM scout_years WHERE label = '2019-2020'), ?, ?, NOW())"
        )->execute(['HTTPS://PHOTOS.Example.ORG/albums/souper-d-unite-2019#photo-12', $this->adminId]);

        $run = $this->drive($this->deposit('galeries-exemple.zip'));
        $this->assertSame(1, $run->analysis['present']);
        $run = $this->drive($this->service->confirm($run, ['2018-2019']) ?? $this->fail('Not confirmed.'));

        $this->assertSame(3, $run->report['imported']);
        $this->assertSame(1, $run->report['present']);
        $this->assertSame(
            'Souper (ajouté à la main)',
            $this->pdo->query("SELECT title FROM gallery_albums WHERE external_url LIKE 'HTTPS%'")->fetchColumn()
        );
    }

    /**
     * D5: an album of a year that was not ticked is ignored — never filed
     * under the current year — and its page is not even read.
     */
    public function testAnAlbumOfAnUntickedYearIsIgnoredNeverMovedToTheCurrentYear(): void
    {
        $run = $this->drive($this->deposit('galeries-exemple.zip'));
        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(1, $run->report['imported']);
        $this->assertSame(3, $run->report['ignored']['Année scoute non créée']);
        $this->assertSame(['https://photos.example.org/albums/souper-d-unite-2019'], array_keys($this->albums()));
        $this->assertNull((new ScoutYearService($this->pdo))->findByLabel('2018-2019'));
        $this->assertSame(['https://photos.example.org/albums/souper-d-unite-2019'], $this->pages->fetched);
    }

    /**
     * One album per execution slice, even with a whole slice budget: one
     * remote page can take several seconds, and the deadline is only
     * checked between two albums.
     */
    public function testOneAlbumIsReadPerExecutionSlice(): void
    {
        $this->service = $this->runService(60.0);
        $run = $this->drive($this->deposit('galeries-exemple.zip'));
        $this->assertSame(Run::ANALYSED, $run->state, 'The analysis is not throttled: one slice is enough.');
        $run = $this->service->confirm($run, ['2018-2019']) ?? $this->fail('Not confirmed.');

        $run = $this->service->advance($this->importer, $run);

        $this->assertSame(Run::RUNNING, $run->state);
        $this->assertSame(1, $run->position());
        $this->assertSame(1, $run->report['imported']);
        $this->assertCount(1, $this->pages->fetched);

        $slices = 1;
        while ($run->state === Run::RUNNING && $slices < 20) {
            $run = $this->service->advance($this->importer, $run);
            $slices++;
        }
        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(5, $slices, 'Five albums inspected, five slices.');
    }

    public function testEveryInvalidAlbumIsIgnoredWithItsReasonAndTheOthersAreNotBlocked(): void
    {
        $run = $this->drive($this->deposit('elements-invalides.zip'));

        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);
        $this->assertSame(9, $run->analysis['total']);
        $this->assertSame(0, $run->analysis['to_import']);
        $this->assertSame([
            'Ligne illisible' => 1,
            'Identifiant d\'origine absent ou qui n\'est pas une chaîne de caractères' => 1,
            GalleryLinksImporter::REASON_URL => 2,
            GalleryLinksImporter::REASON_DATE => 2,
            GalleryLinksImporter::REASON_YEAR => 1,
            GalleryLinksImporter::REASON_SECTION => 1,
            GalleryLinksImporter::REASON_TITLE_TOO_LONG => 1,
        ], $run->analysis['ignored']);
        foreach ($run->analysis['samples'] as $sample) {
            $this->assertMatchesRegularExpression('/^(album-01\d\d|ligne \d+)$/', $sample['reference'], 'A report shows references, never a title.');
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedArchives(): array
    {
        return [
            'wrong fingerprint' => ['archive-invalide-empreinte-fausse.zip', 'empreinte SHA-256'],
            'unknown major version' => ['archive-invalide-version-majeure.zip', 'version 2.0.0'],
            'member missing from the inventory' => ['archive-invalide-fichier-non-inventorie.zip', 'ne cite pas'],
        ];
    }

    #[DataProvider('rejectedArchives')]
    public function testAnInvalidArchiveIsRejectedBeforeAnyWrite(string $fixture, string $expected): void
    {
        $run = $this->drive($this->deposit($fixture));

        $this->assertSame(Run::ABANDONED, $run->state);
        $this->assertStringContainsString($expected, (string) $run->rejectionReason);
        $this->assertSame([], $this->albums());
    }

    public function testTheAddressLookupIgnoresTheCaseOfTheStoredAddress(): void
    {
        $this->pdo->prepare(
            "INSERT INTO gallery_albums (type, title, album_date, scout_year_id, external_url, created_by, created_at)
             VALUES ('external', 'Album', '2019-11-09', (SELECT id FROM scout_years WHERE label = '2019-2020'), ?, ?, NOW())"
        )->execute(['HTTPS://Photos.EXAMPLE.org/a_b%25#x', $this->adminId]);
        $id = (int) $this->pdo->lastInsertId();
        $repository = new AlbumRepository($this->pdo);

        $this->assertSame([$id], array_column($repository->findExternalByUrlPrefix('https://photos.example.org/a_b%25'), 'id'));
        $this->assertSame([], $repository->findExternalByUrlPrefix('https://photos.example.org/aXb'));
        $this->assertSame($id, $this->writer()->findExternalAlbumByUrl('https://photos.example.org/a_b%25'));
    }

    private function runService(float $sliceSeconds): RunService
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runs = new RunRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32)));
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo));

        $sections = $this->createStub(SectionService::class);
        $sections->method('findByDeskCode')->willReturnCallback(
            fn (string $code): ?array => isset($this->sections[$code]) ? ['id' => $this->sections[$code]] : null
        );
        $this->importer = new GalleryLinksImporter($this->writer(), new SectionByDeskCode($sections), $this->pages);

        return new RunService(
            $runs,
            new ImportEngine($this->pdo, $runs, new ItemMappingRepository($this->pdo), $years, $uploads, new ManifestReader(), $journal, $sliceSeconds),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal
        );
    }

    private function writer(): ExternalAlbumImportService
    {
        return new ExternalAlbumImportService(
            new AlbumRepository($this->pdo),
            new OgImageStore(new UploadHandler(new FileRepository($this->pdo), $this->storage)),
            new SettingService(new SettingRepository($this->pdo))
        );
    }

    private function deposit(string $fixture): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_chunk_' . bin2hex(random_bytes(6));
        copy(self::FIXTURES . $fixture, $chunk);
        $result = $this->service->receiveChunk($this->importer, bin2hex(random_bytes(16)), 'test-session', 0, $chunk, true, $fixture, $this->adminId);
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
     * @return array<string, array<string, mixed>> by address
     */
    private function albums(): array
    {
        $albums = [];
        foreach ($this->pdo->query('SELECT * FROM gallery_albums ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $albums[(string) $row['external_url']] = $row;
        }

        return $albums;
    }

    private function yearLabel(int $id): string
    {
        return (string) $this->pdo->query('SELECT label FROM scout_years WHERE id = ' . $id)->fetchColumn();
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
