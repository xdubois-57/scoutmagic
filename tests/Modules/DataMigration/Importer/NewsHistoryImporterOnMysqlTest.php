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
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\DataMigration\Importer\AnalysisSummary;
use Modules\DataMigration\Importer\NewsHistoryImporter;
use Modules\DataMigration\Manifest\ManifestReader;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\DataMigration\Repository\Run;
use Modules\DataMigration\Repository\RunRepository;
use Modules\DataMigration\Service\ImportEngine;
use Modules\DataMigration\Service\MissingScoutYears;
use Modules\DataMigration\Service\RunService;
use Modules\DataMigration\Service\UploadStorage;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Service\ArticleService;
use Modules\News\Service\NewsException;
use Modules\News\Service\NewsImportWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * « Actualités et formulaires » end to end, on the engine production runs:
 * the example archive of the format document imported through the real
 * news writer, the invalid cases with their reasons, a re-run and an
 * interruption without a duplicate, and the lock surviving the data
 * migration's own records.
 *
 * On MariaDB/MySQL because the correspondence lookup a form's parent goes
 * through is the VARBINARY key compared byte for byte, and each item is a
 * transaction of its own.
 */
#[Group('database')]
final class NewsHistoryImporterOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const FIXTURES = __DIR__ . '/../../../fixtures/data-migration/scoutmagic-news-history';

    private \PDO $pdo;
    private string $storage;
    private int $adminId;
    private NewsHistoryImporter $importer;
    private RunService $service;
    private ArticleRepository $articles;
    /** @var list<string> */
    private array $zips = [];

    /** @var list<string> */
    private array $trees = [];

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/dm_news_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $encryption = new EncryptionService(random_bytes(32), random_bytes(32));
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute([$encryption->encrypt('admin@test.com', 'user_accounts.email'), $encryption->blindIndex('admin@test.com', 'email')]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $journal = new JournalService(new JournalRepository($this->pdo));
        $files = new FileRepository($this->pdo);
        $this->articles = new ArticleRepository($this->pdo);
        $mappings = new ItemMappingRepository($this->pdo);
        $this->importer = new NewsHistoryImporter(
            new NewsImportWriter(
                $this->articles,
                new FormRepository($this->pdo),
                new FormFieldRepository($this->pdo),
                new ShortUrlService(new ShortUrlRepository($this->pdo, $encryption)),
                $files,
                new UploadHandler($files, $this->storage),
                $this->storage,
                $journal
            ),
            $mappings
        );

        $runs = new RunRepository($this->pdo, $encryption);
        $uploads = new UploadStorage($this->storage);
        $years = new MissingScoutYears(new ScoutYearService($this->pdo));
        $this->service = new RunService(
            $runs,
            new ImportEngine($this->pdo, $runs, $mappings, $years, $uploads, new ManifestReader(), $journal, 0.0),
            $years,
            $uploads,
            new ChunkedUploadStore($this->storage),
            $journal
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        foreach ($this->trees as $tree) {
            exec('rm -rf ' . escapeshellarg($tree));
        }
        exec('rm -rf ' . escapeshellarg($this->storage));
    }

    /**
     * The example the format document points exporters at is valid, and
     * the ZIP shipped beside it is that same tree.
     */
    public function testTheExampleArchiveIsTheTreeItShipsWithAndItsManifestIsTrue(): void
    {
        $manifest = json_decode((string) file_get_contents(self::FIXTURES . '/exemple/manifest.json'), true);
        $this->assertSame('scoutmagic-news-history', $manifest['format']);
        $this->assertSame('1.0.0', $manifest['format_version']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open(self::FIXTURES . '/exemple.zip'));
        $this->assertSame((string) file_get_contents(self::FIXTURES . '/exemple/manifest.json'), $zip->getFromName('manifest.json'));
        $this->assertSame(count($manifest['files']) + 1, $zip->numFiles);
        foreach ($manifest['files'] as $file) {
            $bytes = (string) file_get_contents(self::FIXTURES . '/exemple/' . $file['path']);
            $this->assertSame($file['sha256'], hash('sha256', $bytes), $file['path']);
            $this->assertSame($file['size'], strlen($bytes), $file['path']);
            $this->assertSame($bytes, $zip->getFromName($file['path']), $file['path']);
        }
        $zip->close();
    }

    public function testTheExampleIsImportedOnlineLockedClosedAndAnnouncedNowhere(): void
    {
        $run = $this->analysed($this->copy(self::FIXTURES . '/exemple.zip'));

        $this->assertSame(4, $run->analysis['to_import']);
        $this->assertSame(0, $this->rows('news_articles'), 'The analysis wrote something.');
        $notes = array_map(static fn ($note): string => $note->text, $this->importer->previewNotes($this->summary($run)));
        $this->assertContains('1 formulaire est importé et fermé. Le fichier ne contient aucune réponse.', $notes);
        $this->assertContains('1 actualité a une visibilité inconnue. Elle sera visible des seuls « Membres connectés ».', $notes);

        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(4, $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);

        $rows = $this->pdo->query('SELECT * FROM news_articles ORDER BY title')->fetchAll(\PDO::FETCH_ASSOC);
        $byTitle = array_column($rows, null, 'title');
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('published', $row['status']);
            $this->assertNotNull($row['imported_at']);
            $this->assertSame('scoutmagic-news-history:ancien-site-unite-exemple', $row['import_source']);
            $this->assertSame(0, (int) $row['publication_notice_pending']);
        }
        $souper = $byTitle['Souper aux spaghettis 2023'];
        $this->assertSame('identified', $souper['visibility']);
        $this->assertSame(
            (new \DateTimeImmutable('2023-03-02T18:00:00+01:00'))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
            $souper['published_at']
        );
        $this->assertNotNull($souper['image_file_id']);
        $closed = $byTitle['Fermeture du local pendant les travaux'];
        $this->assertSame('identified', $closed['visibility'], 'An unknown visibility is never public.');
        $this->assertNull($closed['summary']);
        $this->assertNull($closed['image_file_id']);
        $this->assertSame('public', $byTitle['Inscriptions pour la nouvelle année']['visibility']);

        $form = $this->pdo->query('SELECT * FROM news_forms WHERE news_article_id = ' . (int) $souper['id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $form['is_force_closed']);
        $this->assertNull($form['digest_email']);
        $this->assertSame(0, (int) $form['issues_ticket']);
        $this->assertNull($form['finance_account_id']);
        $this->assertNull($form['finance_category_id']);
        $types = $this->pdo->query('SELECT field_type FROM news_form_fields WHERE form_id = ' . (int) $form['id'] . ' ORDER BY sort_order')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['text', 'text', 'number', 'number', 'radio', 'long_text'], $types);
        $body = (string) $this->pdo->query('SELECT confirmation_text FROM news_form_fields WHERE form_id = ' . (int) $form['id'] . ' AND sort_order = 0')->fetchColumn();
        $this->assertMatchesRegularExpression('~<img[^>]*src="/files/\d+"~', $body);
        $this->assertStringNotContainsString('media/', $body);

        $this->assertSame(0, $this->rows('news_form_responses'));
        $this->assertSame(0, $this->rows('scheduled_actions'));
    }

    public function testEveryInvalidCaseIsIgnoredWithItsReason(): void
    {
        $expected = json_decode((string) file_get_contents(self::FIXTURES . '/invalides/attendu.json'), true);
        $run = $this->analysed($this->zipOf(self::FIXTURES . '/invalides'));
        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(count($expected['imported']), $run->report['imported']);
        $this->assertSame(0, $run->report['errors']);
        $reasons = [];
        foreach ($run->report['samples'] as $sample) {
            $reasons[$sample['reference']] = $sample['reason'];
        }
        ksort($reasons);
        $ignored = $expected['ignored'];
        ksort($ignored);
        $this->assertSame($ignored, $reasons);

        $article = $this->pdo->query("SELECT * FROM news_articles WHERE title = 'La seule actualité valide'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($article['image_file_id'], 'An image missing from the archive leaves its article without it.');
    }

    /**
     * An image past the site's pixel ceiling is left out like any other
     * unusable image: its article is imported without it, not failed at
     * every re-run by the upload pipeline's own refusal.
     */
    public function testAnImagePastThePixelCeilingLeavesItsArticleWithoutIt(): void
    {
        $tree = sys_get_temp_dir() . '/dm_news_tree_' . bin2hex(random_bytes(6));
        $this->copyTree(self::FIXTURES . '/exemple', $tree);
        // A PNG whose header claims 10 000 × 10 000 pixels: 100 MP, twice the ceiling.
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 10000, 10000) . "\x08\x02\x00\x00\x00"
            . pack('N', 0) . str_repeat("\x00", 64);
        $sha = hash('sha256', $png);
        file_put_contents($tree . '/media/' . $sha . '.png', $png);
        $article = json_decode((string) file_get_contents($tree . '/articles/a-1187.json'), true);
        $article['image'] = 'media/' . $sha . '.png';
        file_put_contents($tree . '/articles/a-1187.json', json_encode($article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manifest = json_decode((string) file_get_contents($tree . '/manifest.json'), true);
        $manifest['files'][] = ['path' => 'media/' . $sha . '.png', 'sha256' => $sha, 'size' => strlen($png)];
        foreach ($manifest['files'] as &$file) {
            $file['sha256'] = hash_file('sha256', $tree . '/' . $file['path']);
            $file['size'] = filesize($tree . '/' . $file['path']);
        }
        unset($file);
        file_put_contents($tree . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $run = $this->analysed($this->zipOf($tree));
        // The preview names every cause, not the weight alone.
        $notes = array_map(static fn ($note): string => $note->text, $this->importer->previewNotes($this->summary($run)));
        $this->assertContains(
            '1 image est illisible, absente du fichier, d\'un type non accepté, trop lourde (plus de 5 Mo) '
                . 'ou trop grande (plus de 50 mégapixels). Son actualité est importée sans elle.',
            $notes
        );
        $run = $this->drive($this->service->confirm($run, []) ?? $this->fail('Not confirmed.'));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(0, $run->report['errors']);
        $souper = $this->pdo->query("SELECT * FROM news_articles WHERE title = 'Souper aux spaghettis 2023'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($souper, 'The article itself is imported.');
        $this->assertNull($souper['image_file_id']);
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        $this->trees[] = $to;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($items as $item) {
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0700, true) : copy($item->getPathname(), $target);
        }
    }

    public function testARerunAndAnInterruptionWriteNothingTwice(): void
    {
        $run = $this->analysed($this->copy(self::FIXTURES . '/exemple.zip'));
        $run = $this->service->confirm($run, []) ?? $this->fail('Not confirmed.');
        $run = $this->service->advance($this->importer, $run);
        $run = $this->service->advance($this->importer, $run);
        $this->assertSame(Run::RUNNING, $run->state);
        $run = $this->service->pause($run);
        $run = $this->drive($this->service->resume($run));

        $this->assertSame(Run::DONE, $run->state);
        $this->assertSame(3, $this->rows('news_articles'));
        $this->assertSame(4, $this->rows('data_migration_items'));

        $again = $this->drive($this->service->restart($this->importer, $run, $this->adminId) ?? $this->fail('No restart.'));
        $this->assertSame(4, $again->analysis['present']);
        $again = $this->drive($this->service->confirm($again, []) ?? $this->fail('Not confirmed.'));
        $this->assertSame(0, $again->report['imported']);
        $this->assertSame(4, $again->report['present']);
        $this->assertSame(3, $this->rows('news_articles'));
        $this->assertSame(2, $this->rows('news_form_fields WHERE field_type = \'number\''));
    }

    /**
     * D11: the lock is the article's, not the migration's. With every record
     * of the data migration module gone — what disabling and cleaning it
     * out would leave — the imported article still refuses every change.
     */
    public function testTheLockOutlivesTheDataMigrationsOwnRecords(): void
    {
        $this->drive($this->service->confirm($this->analysed($this->copy(self::FIXTURES . '/exemple.zip')), []) ?? $this->fail('Not confirmed.'));
        $this->pdo->exec('DELETE FROM data_migration_items');
        $this->pdo->exec('DELETE FROM data_migration_runs');

        $id = (int) $this->pdo->query("SELECT id FROM news_articles WHERE title = 'Souper aux spaghettis 2023'")->fetchColumn();
        $articles = new ArticleService(
            $this->articles,
            new FormRepository($this->pdo),
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new ShortUrlService(new ShortUrlRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32)))),
            new FileRepository($this->pdo)
        );

        foreach (['unpublish', 'delete', 'publish'] as $method) {
            try {
                $articles->{$method}($id);
                $this->fail('Accepted: ' . $method);
            } catch (NewsException) {
                $this->addToAssertionCount(1);
            }
        }
        $article = $this->articles->findById($id) ?? $this->fail('The article is gone.');
        $this->assertTrue($article->isImported());
        $this->assertSame(Article::STATUS_PUBLISHED, $article->status);
        // And the responses never arrive through a digest or a ticket.
        $this->assertSame([], (new FormRepository($this->pdo))->findAllWithDigestEmail());
        $this->assertSame([], (new FormRepository($this->pdo))->findAllIssuingTickets());
        $this->assertSame(0, (new FormResponseRepository($this->pdo, new EncryptionService(random_bytes(32), random_bytes(32))))->countByFormId(
            (int) $this->pdo->query('SELECT id FROM news_forms WHERE news_article_id = ' . $id)->fetchColumn()
        ));
    }

    private function analysed(string $zip): Run
    {
        $chunk = sys_get_temp_dir() . '/dm_chunk_' . bin2hex(random_bytes(6));
        rename($zip, $chunk);
        $result = $this->service->receiveChunk($this->importer, bin2hex(random_bytes(16)), 'test-session', 0, $chunk, true, 'actualites.zip', $this->adminId);
        @unlink($chunk);
        $run = $this->drive($result['run'] ?? $this->fail('No run.'));
        $this->assertSame(Run::ANALYSED, $run->state, (string) $run->rejectionReason);

        return $run;
    }

    private function drive(Run $run): Run
    {
        for ($i = 0; $i < 300 && in_array($run->state, [Run::UPLOADED, Run::CONFIRMED, Run::RUNNING], true); $i++) {
            $run = $this->service->advance($this->importer, $run);
        }

        return $run;
    }

    private function summary(Run $run): AnalysisSummary
    {
        $a = $run->analysis;

        return new AnalysisSummary($a['total'], $a['to_import'], $a['present'], $a['ambiguous'], $a['ignored'], $a['tags'], $a['missing_years']);
    }

    private function copy(string $path): string
    {
        $copy = sys_get_temp_dir() . '/dm_news_zip_' . bin2hex(random_bytes(6)) . '.zip';
        copy($path, $copy);
        $this->zips[] = $copy;

        return $copy;
    }

    /** A fixture tree as an archive: its manifest and the files it lists, nothing else. */
    private function zipOf(string $directory): string
    {
        $path = sys_get_temp_dir() . '/dm_news_zip_' . bin2hex(random_bytes(6)) . '.zip';
        $this->zips[] = $path;
        $manifest = json_decode((string) file_get_contents($directory . '/manifest.json'), true);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFile($directory . '/manifest.json', 'manifest.json');
        foreach ($manifest['files'] as $file) {
            $zip->addFile($directory . '/' . $file['path'], $file['path']);
        }
        $zip->close();

        return $path;
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
