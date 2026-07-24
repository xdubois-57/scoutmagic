<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Security\Role;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Service\ArticleService;
use Modules\News\Service\NewsException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
class ArticleServiceTest extends TestCase
{
    private \PDO $pdo;
    private ArticleService $service;
    private ArticleRepository $articleRepository;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);

        $this->articleRepository = new ArticleRepository($this->pdo);
        $formRepository = new FormRepository($this->pdo);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));

        $this->service = new ArticleService($this->articleRepository, $formRepository, $editableContentService, $shortUrlService);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateStoresArticleBodyAndGeneratesShortUrl(): void
    {
        $article = $this->service->create('Camp d\'été', '<p>Bienvenue</p>', Article::VISIBILITY_PUBLIC, true, 'camp,ete', null, $this->authorId);

        $this->assertSame('Camp d\'été', $article->title);
        $this->assertSame('<p>Bienvenue</p>', $this->service->getBodyHtml($article->id));
        $this->assertNotNull($article->shortUrlCode);
        $this->assertSame(6, strlen($article->shortUrlCode));
    }

    public function testCreateSanitizesBodyHtml(): void
    {
        $article = $this->service->create('Titre', '<p>Texte</p><script>alert(1)</script>', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->assertStringNotContainsString('<script>', $this->service->getBodyHtml($article->id));
    }

    public function testCreateWithDirectLinkVisibilityForcesIsIndexedFalse(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_DIRECT_LINK, true, 'mots', '2027-01-01', $this->authorId);

        $this->assertFalse($article->isIndexed);
        $this->assertNull($article->seoKeywords);
        $this->assertNull($article->seoStopDate);
    }

    public function testCreateRejectsInvalidVisibility(): void
    {
        $this->expectException(NewsException::class);
        $this->service->create('Titre', '<p>x</p>', 'bogus', false, null, null, $this->authorId);
    }

    public function testUpdateAlsoEnforcesDirectLinkSeoRule(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_PUBLIC, true, 'mots', '2027-01-01', $this->authorId);

        $updated = $this->service->update($article->id, 'Titre', '<p>y</p>', Article::VISIBILITY_DIRECT_LINK, true, 'mots', '2027-01-01', $this->authorId);

        $this->assertFalse($updated->isIndexed);
        $this->assertNull($updated->seoKeywords);
    }

    public function testCanViewPublicArticleForAnyRole(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->assertTrue($this->service->canView($article, Role::PUBLIC));
    }

    public function testCanViewChiefArticleRequiresChiefRole(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId);
        $this->assertFalse($this->service->canView($article, Role::IDENTIFIED));
        $this->assertTrue($this->service->canView($article, Role::CHIEF));
    }

    public function testCanViewAdminArticleRequiresAdminRole(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_ADMIN, false, null, null, $this->authorId);
        $this->assertFalse($this->service->canView($article, Role::CHIEF));
        $this->assertTrue($this->service->canView($article, Role::ADMIN));
    }

    public function testCanViewDirectLinkArticleForAnyone(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId);
        $this->assertTrue($this->service->canView($article, Role::PUBLIC));
    }

    public function testCanEditAllowsAuthorButNotOtherChief(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->assertTrue($this->service->canEdit($article, Role::CHIEF, $this->authorId));
        $this->assertFalse($this->service->canEdit($article, Role::CHIEF, 999));
        $this->assertTrue($this->service->canEdit($article, Role::ADMIN, 999));
    }

    public function testFindPublicListExcludesDirectLinkAndChiefArticles(): void
    {
        $this->service->create('Public', '<p>x</p>', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->service->create('Chef', '<p>x</p>', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId);
        $this->service->create('Lien direct', '<p>x</p>', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId);

        $list = $this->service->findPublicList();

        $this->assertCount(1, $list);
        $this->assertSame('Public', $list[0]->title);
    }

    public function testDeleteRemovesArticleAndBodyContent(): void
    {
        $article = $this->service->create('Titre', '<p>x</p>', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->service->delete($article->id);

        $this->assertNull($this->articleRepository->findById($article->id));
        $this->assertSame('', $this->service->getBodyHtml($article->id));
    }
}
