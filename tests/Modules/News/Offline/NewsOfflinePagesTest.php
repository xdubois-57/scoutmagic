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

    /**
     * An article as the public list shows it: published, and dated a minute
     * ago. Articles are born drafts (#845), and a draft is listed nowhere.
     */
    private function published(
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        int $createdBy
    ): int {
        $id = $this->articles->create($title, $visibility, $isIndexed, $seoKeywords, $seoStopDate, $createdBy);
        $this->articles->setPublication($id, Article::STATUS_PUBLISHED, (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'));

        return $id;
    }

    // Not yet on /news for anyone (#845): a draft, and an article scheduled
    // for later, are not held either.
    public function testADraftAndAScheduledArticleAreNotHeld(): void
    {
        $draft = $this->articles->create('Brouillon', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $scheduled = $this->articles->create('Programmé', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $this->articles->setPublication($scheduled, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');

        $pages = $this->provider->pagesFor(Role::PUBLIC, null);

        $this->assertNotContains('/news/' . $draft, $pages);
        $this->assertNotContains('/news/' . $scheduled, $pages);
        $this->assertSame(['/news'], $pages);
    }

    public function testTheListAndTheArticlesOfItsFirstPageAreHeld(): void
    {
        $older = $this->published('Ancien', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $recent = $this->published('Récent', Article::VISIBILITY_PUBLIC, false, null, null, 1);

        $pages = $this->provider->pagesFor(Role::PUBLIC, null);

        $this->assertSame('/news', $pages[0]);
        $this->assertEqualsCanonicalizing(['/news', '/news/' . $older, '/news/' . $recent], $pages);
    }

    public function testOnlyWhatThisRoleSeesListedIsHeld(): void
    {
        $public = $this->published('Public', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        $members = $this->published('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, 1);
        $chiefs = $this->published('Chefs', Article::VISIBILITY_CHIEF, false, null, null, 1);

        $this->assertNotContains('/news/' . $members, $this->provider->pagesFor(Role::PUBLIC, null));
        $forMember = $this->provider->pagesFor(Role::IDENTIFIED, 'parent@test.example');
        $this->assertContains('/news/' . $public, $forMember);
        $this->assertContains('/news/' . $members, $forMember);
        // Never listed on /news, so never held — even for a chief.
        $this->assertNotContains('/news/' . $chiefs, $this->provider->pagesFor(Role::CHIEF, 'chef@test.example'));
    }

    public function testAnArticleBeyondTheFirstPageIsNotHeld(): void
    {
        $first = $this->published('Le tout premier', Article::VISIBILITY_PUBLIC, false, null, null, 1);
        // Published in the same second as the others: dated years back, so
        // « the oldest » is not left to the order of a tie.
        $this->articles->setPublication($first, Article::STATUS_PUBLISHED, '2020-01-01 00:00:00');
        for ($i = 0; $i < NewsController::LIST_PER_PAGE; $i++) {
            $this->published('Article ' . $i, Article::VISIBILITY_PUBLIC, false, null, null, 1);
        }

        $pages = $this->provider->pagesFor(Role::PUBLIC, null);

        $this->assertCount(NewsController::LIST_PER_PAGE + 1, $pages);
        $this->assertNotContains('/news/' . $first, $pages);
    }

    public function testTheManagementPagesAreNeverHeld(): void
    {
        $this->published('Public', Article::VISIBILITY_PUBLIC, false, null, null, 1);

        $pages = $this->provider->pagesFor(Role::ADMIN, 'admin@test.example');

        foreach (['/news/manage', '/news/create', '/news/scan'] as $management) {
            $this->assertNotContains($management, $pages);
        }
        foreach ($pages as $page) {
            $this->assertMatchesRegularExpression('~^/news(/\d+)?$~', $page);
        }
    }
}
