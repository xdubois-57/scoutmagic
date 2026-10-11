<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Offline;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Controller\NewsController;
use Modules\News\Offline\NewsOfflinePages;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Service\ArticleService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The news held offline (#883): the list, and the articles of its first
 * page as this role sees it — never the management pages, never older
 * articles.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class NewsOfflinePagesTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articles;
    private NewsOfflinePages $provider;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->pdo = $pdo;
        NewsTestHelper::createTables($pdo);
        $this->articles = new ArticleRepository($pdo);
        $this->provider = new NewsOfflinePages(new ArticleService(
            $this->articles,
            new FormRepository($pdo),
            new EditableContentService(new EditableContentRepository($pdo)),
            new ShortUrlService(new ShortUrlRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))),
            new FileRepository($pdo)
        ));
    }

    public function testTheListAndTheArticlesOfItsFirstPageAreHeld(): void
    {
        $older = $this->articles->create('Ancien', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $recent = $this->articles->create('Récent', Article::VISIBILITY_PUBLIC, false, null, null, 1);

        $pages = $this->provider->pagesFor(Role::PUBLIC, null);

        $this->assertSame('/news', $pages[0]);
        $this->assertEqualsCanonicalizing(['/news', '/news/' . $older, '/news/' . $recent], $pages);
    }

    public function testOnlyWhatThisRoleSeesListedIsHeld(): void
    {
        $public = $this->articles->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $members = $this->articles->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, 1);
        $chiefs = $this->articles->create('Chefs', Article::VISIBILITY_CHIEF, false, null, null, 1);

        $this->assertNotContains('/news/' . $members, $this->provider->pagesFor(Role::PUBLIC, null));
        $forMember = $this->provider->pagesFor(Role::IDENTIFIED, 'parent@test.example');
        $this->assertContains('/news/' . $public, $forMember);
        $this->assertContains('/news/' . $members, $forMember);
        // Never listed on /news, so never held — even for a chief.
        $this->assertNotContains('/news/' . $chiefs, $this->provider->pagesFor(Role::CHIEF, 'chef@test.example'));
    }

    public function testAnArticleBeyondTheFirstPageIsNotHeld(): void
    {
        $first = $this->articles->create('Le tout premier', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        // Created in the same second as the others: dated a year back, so
        // « the oldest » is not left to the order of a tie.
        $this->pdo->exec("UPDATE news_articles SET created_at = '2020-01-01 00:00:00' WHERE id = {$first}");
        for ($i = 0; $i < NewsController::LIST_PER_PAGE; $i++) {
            $this->articles->create('Article ' . $i, Article::VISIBILITY_PUBLIC, false, null, null, 1);
        }

        $pages = $this->provider->pagesFor(Role::PUBLIC, null);

        $this->assertCount(NewsController::LIST_PER_PAGE + 1, $pages);
        $this->assertNotContains('/news/' . $first, $pages);
    }

    public function testTheManagementPagesAreNeverHeld(): void
    {
        $this->articles->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, 1);

        $pages = $this->provider->pagesFor(Role::ADMIN, 'admin@test.example');

        foreach (['/news/manage', '/news/create', '/news/scan'] as $management) {
            $this->assertNotContains($management, $pages);
        }
        foreach ($pages as $page) {
            $this->assertMatchesRegularExpression('~^/news(/\d+)?$~', $page);
        }
    }
}
