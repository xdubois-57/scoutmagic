<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\File\FileRepository;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));

        $this->service = new ArticleService($this->articleRepository, $formRepository, $editableContentService, $shortUrlService, new \Core\File\FileRepository($this->pdo));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateGeneratesShortUrl(): void
    {
        $article = $this->service->create('Camp d\'été', Article::VISIBILITY_PUBLIC, true, 'camp,ete', null, $this->authorId, 'Résumé.', 1);

        $this->assertSame('Camp d\'été', $article->title);
        $this->assertNotNull($article->shortUrlCode);
        $this->assertSame(6, strlen($article->shortUrlCode));
    }

    public function testCreateWithDirectLinkVisibilityForcesIsIndexedFalse(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_DIRECT_LINK, true, 'mots', '2027-01-01', $this->authorId, 'Résumé.', 1);

        $this->assertFalse($article->isIndexed);
        $this->assertNull($article->seoKeywords);
        $this->assertNull($article->seoStopDate);
    }

    public function testCreateRejectsInvalidVisibility(): void
    {
        $this->expectException(NewsException::class);
        $this->service->create('Titre', 'bogus', false, null, null, $this->authorId, 'Résumé.', 1);
    }

    public function testCreateRejectsAnEmptySummary(): void
    {
        $this->expectException(NewsException::class);
        $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, '   ', 1);
    }

    public function testCreateRejectsAMissingImage(): void
    {
        $this->expectException(NewsException::class);
        $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', null);
    }

    public function testUpdateKeepsTheExistingImageWhenNoneIsReUploaded(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 7);

        $updated = $this->service->update($article->id, 'Nouveau titre', Article::VISIBILITY_PUBLIC, false, null, null, 'Résumé.', null);

        $this->assertSame(7, $updated->imageFileId);
    }

    public function testUpdateReplacesTheImageWhenANewOneIsUploaded(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 7);

        $updated = $this->service->update($article->id, 'Titre', Article::VISIBILITY_PUBLIC, false, null, null, 'Résumé.', 9);

        $this->assertSame(9, $updated->imageFileId);
    }

    public function testUpdateAlsoEnforcesDirectLinkSeoRule(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, true, 'mots', '2027-01-01', $this->authorId, 'Résumé.', 1);

        $updated = $this->service->update($article->id, 'Titre', Article::VISIBILITY_DIRECT_LINK, true, 'mots', '2027-01-01', 'Résumé.', 1);

        $this->assertFalse($updated->isIndexed);
        $this->assertNull($updated->seoKeywords);
    }

    public function testCanViewPublicArticleForAnyRole(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->assertTrue($this->service->canView($article, Role::PUBLIC));
    }

    public function testCanViewChiefArticleRequiresChiefRole(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->assertFalse($this->service->canView($article, Role::IDENTIFIED));
        $this->assertTrue($this->service->canView($article, Role::CHIEF));
    }

    public function testCanViewAdminArticleRequiresAdminRole(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_ADMIN, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->assertFalse($this->service->canView($article, Role::CHIEF));
        $this->assertTrue($this->service->canView($article, Role::ADMIN));
    }

    public function testCanViewDirectLinkArticleForAnyone(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->assertTrue($this->service->canView($article, Role::PUBLIC));
    }

    public function testCanEditAllowsAuthorButNotOtherChief(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertTrue($this->service->canEdit($article, Role::CHIEF, $this->authorId));
        $this->assertFalse($this->service->canEdit($article, Role::CHIEF, 999));
        $this->assertTrue($this->service->canEdit($article, Role::ADMIN, 999));
    }

    public function testCanViewIdentifiedArticleRequiresIdentifiedRole(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertFalse($this->service->canView($article, Role::PUBLIC));
        $this->assertTrue($this->service->canView($article, Role::IDENTIFIED));
        $this->assertTrue($this->service->canView($article, Role::CHIEF));
    }

    public function testFindPublicListExcludesDirectLinkAndChiefArticles(): void
    {
        $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1);

        $list = $this->service->findPublicList(Role::PUBLIC);

        $this->assertCount(1, $list);
        $this->assertSame('Public', $list[0]->title);
    }

    public function testFindPublicListHidesIdentifiedArticleFromAnonymousVisitor(): void
    {
        $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findPublicList(Role::PUBLIC));

        $this->assertSame(['Public'], $titles);
    }

    public function testFindPublicListShowsIdentifiedArticleToSignedInReader(): void
    {
        $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findPublicList(Role::IDENTIFIED));

        sort($titles);
        $this->assertSame(['Membres', 'Public'], $titles);
    }

    public function testFindPublicListPageCountsOnlyWhatTheReaderMaySee(): void
    {
        $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertSame(1, $this->service->findPublicListPage(Role::PUBLIC, 10, 0)['total']);
        $this->assertSame(2, $this->service->findPublicListPage(Role::IDENTIFIED, 10, 0)['total']);
    }

    public function testHomeColumnFollowsTheSameRoleRule(): void
    {
        $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1);

        $anonymous = array_column($this->service->getLatestVisibleArticles(10, 'public'), 'title');
        $identified = array_column($this->service->getLatestVisibleArticles(10, 'identified'), 'title');

        sort($anonymous);
        sort($identified);
        $this->assertSame(['Public'], $anonymous);
        $this->assertSame(['Membres', 'Public'], $identified);
    }

    public function testManagerListIncludesIdentifiedArticles(): void
    {
        $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findManagerList(Role::CHIEF, $this->authorId));

        $this->assertContains('Membres', $titles);
    }

    /**
     * The whole point of the decision recorded in schema.sql: an
     * `identified` article must not be indexable, so the preview a
     * crawler would publish never exists in the first place.
     */
    public function testIdentifiedVisibilityForcesIndexingOffOnCreate(): void
    {
        $article = $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, true, 'camp, scouts', '2099-01-01', $this->authorId, 'Résumé.', 1);

        $this->assertFalse($article->isIndexed);
        $this->assertNull($article->seoKeywords);
        $this->assertNull($article->seoStopDate);
        $this->assertFalse($article->isEffectivelyIndexed());
    }

    public function testIdentifiedVisibilityForcesIndexingOffOnUpdate(): void
    {
        $article = $this->service->create('Public', Article::VISIBILITY_PUBLIC, true, 'camp', null, $this->authorId, 'Résumé.', 1);
        $this->assertTrue($article->isIndexed);

        $updated = $this->service->update($article->id, 'Public', Article::VISIBILITY_IDENTIFIED, true, 'camp', null, 'Résumé.', null);

        $this->assertFalse($updated->isIndexed);
        $this->assertNull($updated->seoKeywords);
    }

    /**
     * Issue #211: a « Membres connectés » article is posted to a group of
     * animateurs like any other, and the crawler that builds that preview
     * never signs in. Staff-only articles are the ones nobody pastes
     * anywhere, and they keep no preview at all.
     */
    public function testEveryVisibilityButTheStaffOnesIsSociallyShareable(): void
    {
        $public = $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $identified = $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1);
        $chief = $this->service->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', 1);
        $admin = $this->service->create('Staff', Article::VISIBILITY_ADMIN, false, null, null, $this->authorId, 'Résumé.', 1);
        $directLink = $this->service->create('Lien', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertTrue($this->service->isSociallyShareable($public));
        // Unlisted, but readable by anyone holding the address — a
        // preview gives away nothing the link itself did not.
        $this->assertTrue($this->service->isSociallyShareable($directLink));
        $this->assertTrue($this->service->isSociallyShareable($identified));
        $this->assertFalse($this->service->isSociallyShareable($chief));
        $this->assertFalse($this->service->isSociallyShareable($admin));
    }

    /**
     * The cover's floor and the preview decision are one decision: an
     * og:image a crawler cannot fetch is a preview with a hole in it, so
     * every shareable visibility gets a `public` cover and the staff ones
     * keep the article's own floor.
     */
    public function testCoverImageRoleMinFollowsTheShareabilityDecision(): void
    {
        $this->assertSame('public', ArticleService::coverImageRoleMin(Article::VISIBILITY_PUBLIC));
        $this->assertSame('public', ArticleService::coverImageRoleMin(Article::VISIBILITY_DIRECT_LINK));
        $this->assertSame('public', ArticleService::coverImageRoleMin(Article::VISIBILITY_IDENTIFIED));
        $this->assertSame('chief', ArticleService::coverImageRoleMin(Article::VISIBILITY_CHIEF));
        $this->assertSame('admin', ArticleService::coverImageRoleMin(Article::VISIBILITY_ADMIN));
    }

    public function testCreateStoresTheCoverOnTheFloorItsVisibilityCallsFor(): void
    {
        $fileId = $this->storeCover('public');

        $this->service->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', $fileId);

        $this->assertSame('chief', $this->roleMinOf($fileId));
    }

    /**
     * The case nothing covered before #211: the editor uploads no file
     * when only the visibility changes, so the cover kept the floor of
     * the old visibility indefinitely. Both directions were wrong, and
     * this is the one that leaked — a public article moved to
     * « Animateurs » left its cover readable by anyone holding the URL.
     */
    public function testUpdateTightensTheCoverWhenTheArticleBecomesStaffOnly(): void
    {
        $fileId = $this->storeCover('public');
        $article = $this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', $fileId);
        $this->assertSame('public', $this->roleMinOf($fileId));

        $this->service->update($article->id, 'Public', Article::VISIBILITY_CHIEF, false, null, null, 'Résumé.', null);

        $this->assertSame('chief', $this->roleMinOf($fileId));
    }

    /**
     * And the other direction, which is what the preview needs: an
     * article moved to « Membres connectés » now emits an og:image, so
     * its cover has to stop being members-only in the same gesture.
     */
    public function testUpdateOpensTheCoverWhenTheArticleBecomesShareable(): void
    {
        $fileId = $this->storeCover('chief');
        $article = $this->service->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', $fileId);
        $this->assertSame('chief', $this->roleMinOf($fileId));

        $this->service->update($article->id, 'Chef', Article::VISIBILITY_IDENTIFIED, false, null, null, 'Résumé.', null);

        $this->assertSame('public', $this->roleMinOf($fileId));
    }

    private function storeCover(string $roleMin): int
    {
        return (new FileRepository($this->pdo))->create(
            'news/images/cover-' . bin2hex(random_bytes(4)) . '.jpg',
            'cover.jpg',
            'image/jpeg',
            1024,
            $roleMin,
            'news',
            $this->authorId
        );
    }

    private function roleMinOf(int $fileId): string
    {
        $file = (new FileRepository($this->pdo))->findById($fileId);
        $this->assertNotNull($file);

        return $file->roleMin;
    }

    public function testDeleteRemovesArticleAndBodyContent(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->service->delete($article->id);

        $this->assertNull($this->articleRepository->findById($article->id));
        $this->assertSame('', $this->service->getBodyHtml($article->id));
    }
}
