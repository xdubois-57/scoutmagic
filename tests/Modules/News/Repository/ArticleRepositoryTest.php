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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

    /**
     * The /news pagination: the SQL page answers exactly like the full
     * read sliced, and the count like counting it.
     */
    public function testFindByVisibilitiesPageMatchesTheFullReadSliced(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $id = $this->repository->create('Article ' . $i, Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
            $this->pdo->exec("UPDATE news_articles SET created_at = '2026-01-0{$i} 10:00:00' WHERE id = {$id}");
        }
        $this->repository->create('Réservé', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId);

        $all = $this->repository->findByVisibilities([Article::VISIBILITY_PUBLIC]);
        $page = $this->repository->findByVisibilitiesPage([Article::VISIBILITY_PUBLIC], 2, 2);

        $this->assertSame(
            array_map(fn(Article $a) => $a->id, array_slice($all, 2, 2)),
            array_map(fn(Article $a) => $a->id, $page)
        );
        $this->assertSame(4, $this->repository->countByVisibilities([Article::VISIBILITY_PUBLIC]));
        $this->assertSame([], $this->repository->findByVisibilitiesPage([], 10, 0));
    }

    /**
     * The homepage column reads the same visibilities the /news list
     * does — the caller decides the set from the reader's role, so this
     * takes a list rather than hardcoding `public` the way it used to.
     */
    public function testFindLatestByVisibilitiesReturnsTheMostRecentOfEachRequestedVisibility(): void
    {
        $public = $this->repository->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->pdo->exec("UPDATE news_articles SET created_at = '2026-01-01 10:00:00' WHERE id = {$public}");
        $identified = $this->repository->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId);
        $this->pdo->exec("UPDATE news_articles SET created_at = '2026-01-02 10:00:00' WHERE id = {$identified}");
        $this->repository->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId);

        $anonymous = $this->repository->findLatestByVisibilities([Article::VISIBILITY_PUBLIC], 10);
        $member = $this->repository->findLatestByVisibilities(
            [Article::VISIBILITY_PUBLIC, Article::VISIBILITY_IDENTIFIED],
            10
        );

        $this->assertSame(['Public'], array_map(fn(Article $a) => $a->title, $anonymous));
        // Most recent first, and the chief-only article in neither list.
        $this->assertSame(['Membres', 'Public'], array_map(fn(Article $a) => $a->title, $member));
    }

    public function testFindLatestByVisibilitiesHonoursTheLimitAndAnEmptySet(): void
    {
        $this->repository->create('A', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->repository->create('B', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);

        $this->assertCount(1, $this->repository->findLatestByVisibilities([Article::VISIBILITY_PUBLIC], 1));
        // An empty set means "nothing to show", never "show everything" —
        // a `WHERE visibility IN ()` would be a syntax error anyway.
        $this->assertSame([], $this->repository->findLatestByVisibilities([], 10));
    }
}
