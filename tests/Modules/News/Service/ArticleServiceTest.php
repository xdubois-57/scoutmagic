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
        $this->service->publish($this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Chef', Article::VISIBILITY_CHIEF, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1)->id);

        $list = $this->service->findPublicList(Role::PUBLIC);

        $this->assertCount(1, $list);
        $this->assertSame('Public', $list[0]->title);
    }

    public function testFindPublicListHidesIdentifiedArticleFromAnonymousVisitor(): void
    {
        $this->service->publish($this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1)->id);

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findPublicList(Role::PUBLIC));

        $this->assertSame(['Public'], $titles);
    }

    public function testFindPublicListShowsIdentifiedArticleToSignedInReader(): void
    {
        $this->service->publish($this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1)->id);

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findPublicList(Role::IDENTIFIED));

        sort($titles);
        $this->assertSame(['Membres', 'Public'], $titles);
    }

    public function testFindPublicListPageCountsOnlyWhatTheReaderMaySee(): void
    {
        $this->service->publish($this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1)->id);

        $this->assertSame(1, $this->service->findPublicListPage(Role::PUBLIC, 10, 0)['total']);
        $this->assertSame(2, $this->service->findPublicListPage(Role::IDENTIFIED, 10, 0)['total']);
    }

    public function testHomeColumnFollowsTheSameRoleRule(): void
    {
        $this->service->publish($this->service->create('Public', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->authorId, 'Résumé.', 1)->id);
        $this->service->publish($this->service->create('Lien direct', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1)->id);

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

    /**
     * A new article is a draft, with no publication date (issue #845):
     * nobody but the news' managers sees it until « Publier ».
     */
    public function testANewArticleIsADraftWithNoPublicationDate(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertSame(Article::STATUS_DRAFT, $article->status);
        $this->assertNull($article->publishedAt);
        $this->assertFalse($this->service->isLive($article));
        $this->assertSame(Article::STATE_DRAFT, $this->service->state($article));
        $this->assertSame([], $this->service->findPublicList(Role::IDENTIFIED));
    }

    /**
     * The three states are read from status and date: published with a
     * date to come is « scheduled », and online at that date with nothing
     * flipping it.
     */
    public function testTheStateIsDerivedFromStatusAndDate(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->articleRepository->setPublication($article->id, Article::STATUS_PUBLISHED, '2026-10-20 08:00:00');
        $scheduled = $this->articleRepository->findById($article->id);
        $this->assertNotNull($scheduled);

        $before = new \DateTimeImmutable('2026-10-20 07:59:00');
        $at = new \DateTimeImmutable('2026-10-20 08:00:00');
        $this->assertSame(Article::STATE_SCHEDULED, $this->service->state($scheduled, $before));
        $this->assertFalse($this->service->isLive($scheduled, $before));
        $this->assertSame(Article::STATE_PUBLISHED, $this->service->state($scheduled, $at));
        $this->assertTrue($this->service->isLive($scheduled, $at));
    }

    /**
     * The public queries apply the same rule in SQL: a draft and a
     * scheduled article are in no list, and the list is sorted by the
     * publication date — not by when the article was written.
     */
    public function testListsHoldOnlyOnlineArticlesSortedByPublicationDate(): void
    {
        $old = $this->service->create('Écrit tôt, publié tard', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $new = $this->service->create('Écrit tard, publié tôt', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->pdo->exec("UPDATE news_articles SET created_at = '2026-01-01 10:00:00' WHERE id = {$old->id}");
        $this->pdo->exec("UPDATE news_articles SET created_at = '2026-02-01 10:00:00' WHERE id = {$new->id}");
        $this->articleRepository->setPublication($old->id, Article::STATUS_PUBLISHED, '2026-03-01 10:00:00');
        $this->articleRepository->setPublication($new->id, Article::STATUS_PUBLISHED, '2026-02-15 10:00:00');
        $this->service->create('Brouillon', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $scheduled = $this->service->create('Programmée', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->articleRepository->setPublication($scheduled->id, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');

        $titles = array_map(fn(Article $a) => $a->title, $this->service->findPublicList(Role::PUBLIC));
        $home = array_column($this->service->getLatestVisibleArticles(10, 'public'), 'title');

        $this->assertSame(['Écrit tôt, publié tard', 'Écrit tard, publié tôt'], $titles);
        $this->assertSame($titles, $home);
        $this->assertSame(2, $this->service->findPublicListPage(Role::PUBLIC, 10, 0)['total']);
        // The managers' list keeps them all.
        $this->assertCount(4, $this->articleRepository->findForManager([Article::VISIBILITY_PUBLIC], $this->authorId));
    }

    public function testPublishDatesTheArticleNowTheFirstTime(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $now = new \DateTimeImmutable('2026-10-11 14:30:00');

        $published = $this->service->publish($article->id, $now);

        $this->assertSame(Article::STATUS_PUBLISHED, $published->status);
        $this->assertSame('2026-10-11 14:30:00', substr((string) $published->publishedAt, 0, 19));
        $this->assertTrue($this->service->isLive($published, $now));
    }

    /**
     * Back to draft and published again, an article keeps the date it was
     * first online — its place in the list does not jump to the top.
     */
    public function testUnpublishThenPublishKeepsThePublicationDate(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->publish($article->id, new \DateTimeImmutable('2026-09-01 08:00:00'));

        $draft = $this->service->unpublish($article->id, new \DateTimeImmutable('2026-10-01 08:00:00'));
        $this->assertSame(Article::STATE_DRAFT, $this->service->state($draft));
        $this->assertSame('2026-09-01 08:00:00', substr((string) $draft->publishedAt, 0, 19));

        $again = $this->service->publish($article->id, new \DateTimeImmutable('2026-10-11 08:00:00'));
        $this->assertSame('2026-09-01 08:00:00', substr((string) $again->publishedAt, 0, 19));
    }

    /**
     * A scheduled article was never online: back to draft, it loses its
     * date to come, and « Publier » puts it online now.
     */
    public function testUnpublishingAScheduledArticleForgetsItsDate(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->articleRepository->setPublication($article->id, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');

        $this->assertNull($this->service->unpublish($article->id)->publishedAt);

        $this->articleRepository->setPublication($article->id, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');
        $now = new \DateTimeImmutable('2026-10-11 09:00:00');
        $this->assertSame('2026-10-11 09:00:00', substr((string) $this->service->publish($article->id, $now)->publishedAt, 0, 19));
    }

    public function testOnlyADraftCanBeDeleted(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId, 'Résumé.', 1);
        $this->service->publish($article->id);

        try {
            $this->service->delete($article->id);
            $this->fail('An online article must not be deleted.');
        } catch (NewsException $e) {
            $this->assertSame('Pour supprimer cette actualité, repassez-la d\'abord en brouillon.', $e->getMessage());
        }
        $this->assertNotNull($this->articleRepository->findById($article->id));

        $this->service->unpublish($article->id);
        $this->service->delete($article->id);
        $this->assertNull($this->articleRepository->findById($article->id));
    }

    /**
     * Before publication only the news' managers may open the page, even
     * with its address in hand — a « Lien direct » article included.
     */
    public function testCanReadANotOnlineArticleOnlyWhenManagingTheNews(): void
    {
        $article = $this->service->create('Titre', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->authorId, 'Résumé.', 1);

        $this->assertFalse($this->service->canRead($article, Role::PUBLIC));
        $this->assertFalse($this->service->canRead($article, Role::IDENTIFIED));
        $this->assertTrue($this->service->canRead($article, Role::CHIEF));

        $online = $this->service->publish($article->id);
        $this->assertTrue($this->service->canRead($online, Role::PUBLIC));
    }

    /**
     * Only a public article may be indexed: any other visibility drops the
     * indexing, its keywords and its stop date.
     */
    public function testOnlyAPublicArticleKeepsItsIndexing(): void
    {
        $public = $this->service->create('Public', Article::VISIBILITY_PUBLIC, true, 'camp', '2027-01-01', $this->authorId, 'Résumé.', 1);
        $members = $this->service->create('Membres', Article::VISIBILITY_IDENTIFIED, true, 'camp', '2027-01-01', $this->authorId, 'Résumé.', 1);

        $this->assertTrue($public->isIndexed);
        $this->assertFalse($members->isIndexed);
        $this->assertNull($members->seoKeywords);
        $this->assertNull($members->seoStopDate);
    }
}
