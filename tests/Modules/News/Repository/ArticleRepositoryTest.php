<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
class ArticleRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $repository;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->repository = new ArticleRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create('Camp d\'été', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $article = $this->repository->findById($id);

        $this->assertNotNull($article);
        $this->assertSame('Camp d\'été', $article->title);
        $this->assertSame(Article::VISIBILITY_PUBLIC, $article->visibility);
        $this->assertFalse($article->hasForm);
        $this->assertFalse($article->isIndexed);
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function testUpdateChangesFields(): void
    {
        $id = $this->repository->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->repository->update($id, 'Nouveau titre', Article::VISIBILITY_CHIEF, true, 'mots,clefs', '2027-01-01');

        $article = $this->repository->findById($id);
        $this->assertSame('Nouveau titre', $article->title);
        $this->assertSame(Article::VISIBILITY_CHIEF, $article->visibility);
        $this->assertTrue($article->isIndexed);
        $this->assertSame('mots,clefs', $article->seoKeywords);
        $this->assertSame('2027-01-01', $article->seoStopDate);
    }

    public function testSetHasFormTogglesTheFlag(): void
    {
        $id = $this->repository->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->repository->setHasForm($id, true);
        $this->assertTrue($this->repository->findById($id)->hasForm);

        $this->repository->setHasForm($id, false);
        $this->assertFalse($this->repository->findById($id)->hasForm);
    }

    public function testSetShortUrlCodeStoresTheCode(): void
    {
        $id = $this->repository->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->repository->setShortUrlCode($id, 'abc123');

        $this->assertSame('abc123', $this->repository->findById($id)->shortUrlCode);
    }

    public function testFindByVisibilitiesFiltersCorrectly(): void
    {
        $this->repository->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->repository->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId);
        $this->repository->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId);

        $articles = $this->repository->findByVisibilities([Article::VISIBILITY_PUBLIC]);

        $this->assertCount(1, $articles);
        $this->assertSame('Public', $articles[0]->title);
    }

    public function testFindForManagerIncludesOwnDirectLinkArticles(): void
    {
        $this->repository->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->repository->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc2', 'idx2']);
        $otherAuthorId = (int) $this->pdo->lastInsertId();
        $this->repository->create('Lien direct autre', Article::VISIBILITY_DIRECT_LINK, false, null, null, $otherAuthorId);

        $articles = $this->repository->findForManager([Article::VISIBILITY_PUBLIC], $this->authorId);
        $titles = array_map(fn($a) => $a->title, $articles);

        $this->assertContains('Public', $titles);
        $this->assertContains('Lien direct', $titles);
        $this->assertNotContains('Lien direct autre', $titles);
    }

    public function testDeleteRemovesTheArticle(): void
    {
        $id = $this->repository->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
