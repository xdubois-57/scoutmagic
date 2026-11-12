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
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Offline\OfflineWhitelist;
use Core\Security\Role;
use Core\View\MenuBuilder;
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
        $this->assertFalse(
            (new EmailRepository($this->pdo))->deleteIfNotStarted($emailId),
            'and the email is still refused deletion'
        );
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
        $this->assertTrue($article?->isImported(), 'the lock column is untouched');
        $this->assertSame(
            1,
            $this->formClosedFlag($articleId),
            'and its form stays closed'
        );
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

    private function formClosedFlag(int $articleId): int
    {
        $stmt = $this->pdo->prepare('SELECT is_force_closed FROM news_forms WHERE news_article_id = ?');
        $stmt->execute([$articleId]);

        return (int) $stmt->fetchColumn();
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
