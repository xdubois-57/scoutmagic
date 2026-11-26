<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\Connection;
use Core\File\DataMigrationDeposits;
use Core\File\Task\PurgeDataMigrationDepositsHandler;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Mail\MailService;
use Core\Offline\OfflineWhitelist;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Core\View\MenuBuilder;
use Modules\DataMigration\Service\UploadStorage;
use Modules\DataMigration\Task\PurgeUploadsHandler;
use Modules\MassMail\Repository\EmailRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\ImportedEmailFixture;
use Tests\Modules\MassMail\MassMailTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * Disabling data_migration once the migration is over (roadmap, lot I):
 * the real ModuleManager, on the real modules directory, deactivates it —
 * and what the imports wrote into the locked modules stays, still locked.
 *
 * The lock itself is tested in each module (ImportedEmailLockTest,
 * ImportedArticleLockTest); what this adds is the deactivation path in
 * between.
 */
#[Group('database')]
final class DisablingTheModuleTest extends TestCase
{
    /** One fixed, prepared query per table a snapshot may read. */
    private const SNAPSHOT_QUERIES = [
        'files' => 'SELECT * FROM files ORDER BY id',
        'mass_mail_attachments' => 'SELECT * FROM mass_mail_attachments ORDER BY id',
        'mass_mail_deliveries' => 'SELECT * FROM mass_mail_deliveries ORDER BY id',
        'mass_mail_emails' => 'SELECT * FROM mass_mail_emails ORDER BY id',
        'news_articles' => 'SELECT * FROM news_articles ORDER BY id',
        'news_forms' => 'SELECT * FROM news_forms ORDER BY id',
    ];

    private \PDO $pdo;
    private ModuleRegistryRepository $registry;
    private string $storage;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->registry = new ModuleRegistryRepository($this->pdo);
        $this->storage = sys_get_temp_dir() . '/dm_disable_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testAnImportedEmailOutlivesTheModuleStillLocked(): void
    {
        MassMailTestHelper::createTables($this->pdo);
        ImportedEmailFixture::staffSection($this->pdo);
        $pdf = ImportedEmailFixture::pdf();
        try {
            $emailId = ImportedEmailFixture::service($this->pdo, $this->storage)->import(
                ImportedEmailFixture::email(
                    'i-disable',
                    null,
                    [],
                    [new \Modules\MassMail\Api\ImportedAttachment($pdf, 'programme.pdf')],
                )
            )->emailId;
        } finally {
            @unlink($pdf);
        }
        $before = $this->snapshot('mass_mail_emails', 'mass_mail_deliveries', 'mass_mail_attachments', 'files');

        $this->disableDataMigration('mass_mail');

        $this->assertSame($before, $this->snapshot(
            'mass_mail_emails',
            'mass_mail_deliveries',
            'mass_mail_attachments',
            'files',
        ), 'deactivating deletes nothing');
        $stmt = $this->pdo->prepare('SELECT imported_at FROM mass_mail_emails WHERE id = ?');
        $stmt->execute([$emailId]);
        $this->assertNotEmpty($stmt->fetchColumn(), 'the lock column is untouched');

        // And it still locks: forced back to a draft, a status that alone
        // would let the delete through, the email is refused for being
        // imported — the guard reads that column and nothing else.
        $this->pdo->prepare('UPDATE mass_mail_emails SET status = ? WHERE id = ?')->execute(['draft', $emailId]);
        $this->assertFalse(
            (new EmailRepository($this->pdo))->deleteIfNotStarted($emailId),
            'the email is still refused deletion'
        );
        $this->assertCount(1, $this->snapshot('mass_mail_emails')['mass_mail_emails']);
    }

    public function testAnImportedArticleOutlivesTheModuleStillLocked(): void
    {
        NewsTestHelper::createTables($this->pdo);
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffré', 'index']);
        $articles = new ArticleRepository($this->pdo);
        $articleId = NewsTestHelper::publish(
            $this->pdo,
            $articles->create('Fermeture du local', Article::VISIBILITY_PUBLIC, false, null, null, (int) $this->pdo->lastInsertId())
        );
        $articles->markImported($articleId, '2026-11-10 09:00:00', 'scoutmagic-news-history:ancien-site');
        $articles->setHasForm($articleId, true);
        (new FormRepository($this->pdo))->create(
            $articleId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            true,
            'chief',
            null,
            null
        );
        $before = $this->snapshot('news_articles', 'news_forms');

        $this->disableDataMigration('news');

        $this->assertSame($before, $this->snapshot('news_articles', 'news_forms'), 'deactivating deletes nothing');
        $article = $articles->findById($articleId);
        // What every lock of the news module reads (ArticleService,
        // ResponseService, NewsController, FormController); their refusals
        // are ImportedArticleLockTest's.
        $this->assertTrue($article?->isImported(), 'the lock column is untouched');
    }

    /**
     * Issue #1259: once the module is disabled its own hourly purge has no
     * handler any more — the scheduler marks it failed and never re-arms it
     * — and the archives it deposited would stay on the disk for good. The
     * core's purge runs on the same scheduler pass regardless, and empties
     * what nothing has touched for a day: an archive, a workspace, a
     * slice's orphaned working copies.
     */
    public function testTheDepositsAreStillPurgedOnceTheModuleIsDisabled(): void
    {
        $directory = $this->storage . '/' . UploadStorage::DIR;
        mkdir($directory . '/ws-abc/', 0700, true);
        mkdir($directory . '/copies-3/', 0700, true);
        $old = time() - DataMigrationDeposits::RETENTION_SECONDS - 60;
        foreach (['run-old.zip', 'ws-abc/calendar.ics', 'copies-3/sm_mail_x'] as $relative) {
            file_put_contents($directory . '/' . $relative, 'données');
            touch($directory . '/' . $relative, $old);
        }
        touch($directory . '/ws-abc', $old);
        touch($directory . '/copies-3', $old);
        file_put_contents($directory . '/run-recent.zip', 'données');

        $this->registry->upsert('data_migration', true, '1.0.0', 1);
        $manager = $this->manager();
        $manager->deactivate('data_migration', 1);
        $manager->loadEnabledModules();
        $this->assertNull(
            $manager->getTaskHandler(PurgeUploadsHandler::MODULE_ID, PurgeUploadsHandler::TASK_KEY),
            'premise: a disabled module\'s own purge has no handler'
        );

        $scheduler = new SchedulerService(new SchedulerRepository($this->pdo));
        $due = new \DateTimeImmutable('-1 minute');
        $scheduler->seed(PurgeUploadsHandler::MODULE_ID, PurgeUploadsHandler::TASK_KEY, PurgeUploadsHandler::REFERENCE, $due);
        $scheduler->seed(
            'core',
            PurgeDataMigrationDepositsHandler::TASK_KEY,
            PurgeDataMigrationDepositsHandler::REFERENCE,
            $due
        );
        $journal = new JournalService(new JournalRepository($this->pdo));
        $runner = new SchedulerRunner(new SchedulerRepository($this->pdo), $journal);
        CoreTaskHandlers::registerAll($runner);
        $runner->setModuleManager($manager);
        $runner->setTaskContext(new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('k', 32), str_repeat('b', 32)),
            $this->createStub(MailService::class),
            $journal,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            $this->storage
        ));

        $runner->processOverdue();

        $this->assertSame(['run-recent.zip'], array_map('basename', glob($directory . '/*') ?: []));
    }

    /**
     * Enables data_migration and the target module, then deactivates
     * data_migration through the real manager and checks it took.
     */
    private function disableDataMigration(string $targetModule): void
    {
        $this->registry->upsert('data_migration', true, '1.0.0', 1);
        $this->registry->upsert($targetModule, true, '1.0.0', 1);

        $this->manager()->deactivate('data_migration', 1);

        $this->assertFalse($this->registry->findByModuleId('data_migration')['enabled'] ?? true);
        $this->assertTrue($this->registry->findByModuleId($targetModule)['enabled'] ?? false);
    }

    private function manager(): ModuleManager
    {
        return new ModuleManager(
            dirname(__DIR__, 3) . '/modules',
            new SettingService(new SettingRepository($this->pdo)),
            new CookieConsentService([]),
            new MenuBuilder(Role::fromString('superadmin')),
            $this->registry,
            new JournalService(new JournalRepository($this->pdo)),
            new Router(),
            null,
            new OfflineWhitelist()
        );
    }

    /**
     * Every row of the given tables, in id order.
     *
     * @param key-of<self::SNAPSHOT_QUERIES> ...$tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(string ...$tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare(self::SNAPSHOT_QUERIES[$table]);
            $stmt->execute();
            $rows[$table] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;
    }
}
