<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Config\SettingService;
use Core\Security\Role;
use Modules\News\Repository\Article;
use Modules\News\Service\ArticleService;
use Modules\News\Service\ArticleShareSourceService;
use PHPUnit\Framework\TestCase;

/**
 * What the news module lets another module share of an article: nothing
 * unless the caller may edit it, its public address, and whether its
 * visibility lets it leave the site at all.
 */
final class ArticleShareSourceServiceTest extends TestCase
{
    public function testAnEditableArticleIsDescribedWithItsShortAddress(): void
    {
        $shared = $this->service($this->article(Article::VISIBILITY_PUBLIC, 'abc'))->describe(5, 'chief', 7);

        $this->assertNotNull($shared);
        $this->assertSame('Inscriptions', $shared->title);
        $this->assertSame('https://unite.example/s/abc', $shared->url);
        $this->assertSame(11, $shared->imageFileId);
        $this->assertTrue($shared->shareable);
    }

    public function testWithoutShortCodeTheAddressIsTheArticlePage(): void
    {
        $this->assertSame(
            'https://unite.example/news/5',
            $this->service($this->article(Article::VISIBILITY_PUBLIC, null))->describe(5, 'chief', 7)?->url
        );
    }

    public function testAnArticleReservedToChiefsIsDescribedButNotShareable(): void
    {
        $shared = $this->service($this->article(Article::VISIBILITY_CHIEF, 'abc'))->describe(5, 'chief', 7);

        $this->assertFalse($shared?->shareable);
    }

    /**
     * Nothing is shared of an article that is not online yet (issue #845):
     * its address would only lead to « pas encore en ligne ».
     */
    public function testAnArticleThatIsNotOnlineIsNotDescribed(): void
    {
        $draft = new Article(5, 'Inscriptions', Article::VISIBILITY_PUBLIC, false, true, null, null, 'abc', 7, '2026-09-01', '2026-09-01', null, 11, Article::STATUS_DRAFT, null);
        $scheduled = new Article(5, 'Inscriptions', Article::VISIBILITY_PUBLIC, false, true, null, null, 'abc', 7, '2026-09-01', '2026-09-01', null, 11, Article::STATUS_PUBLISHED, '2999-01-01 08:00:00');

        $this->assertNull($this->service($draft)->describe(5, 'chief', 7));
        $this->assertNull($this->service($scheduled)->describe(5, 'chief', 7));
        // The page still exists: it answers « pas encore en ligne » itself.
        $this->assertSame('/s/abc', $this->service($draft)->pagePath(5));
    }

    public function testAnArticleTheCallerMayNotEditIsNotThere(): void
    {
        $service = $this->service($this->article(Article::VISIBILITY_PUBLIC, 'abc'));

        $this->assertNull($service->describe(5, 'chief', 8));
        $this->assertNull($service->describe(6, 'chief', 7));
    }

    /**
     * **`pagePath()` asks about no caller, and that is the point** (issue
     * #706, IT-04): the one thing that needs it is the public page of a
     * communication sharing this article, which has to decide a « Voir
     * sur le site » button for a visitor with no account at all.
     *
     * `describe()` above answers only someone who may EDIT the article,
     * so it would say « gone » for every visitor. Here the account that
     * may not edit it gets the same address as the one that may, because
     * the question is whether the article still exists — the page the
     * path names applies its own visibility rule to whoever follows it.
     */
    public function testThePagePathIsAnsweredWhateverTheCaller(): void
    {
        $service = $this->service($this->article(Article::VISIBILITY_PUBLIC, 'abc'));

        $this->assertSame('/s/abc', $service->pagePath(5));
        // The same answer, for an account `describe()` refuses.
        $this->assertNull($service->describe(5, 'chief', 8));
        $this->assertSame('/s/abc', $service->pagePath(5));
    }

    /**
     * The short address when the article has one, like `describe()` and
     * like the article's own `og:url`: a visitor may have been handed
     * either form, and both lead to the same page.
     */
    public function testWithoutAShortCodeThePagePathIsTheArticlePage(): void
    {
        $this->assertSame(
            '/news/5',
            $this->service($this->article(Article::VISIBILITY_PUBLIC, null))->pagePath(5)
        );
    }

    /**
     * **Null is « there is no longer an article there »**, which is what
     * makes the button disappear rather than lead nowhere. A relative
     * path, never an absolute URL: the consumer absolutises it once, and
     * this answer does not depend on the site knowing its own address.
     */
    public function testAnArticleThatIsNotThereHasNoPagePath(): void
    {
        $this->assertNull($this->service($this->article(Article::VISIBILITY_PUBLIC, 'abc'))->pagePath(6));
    }

    /**
     * A staff-only article still HAS a page, and this says so: whether it
     * may leave the site is `describe()`'s `shareable`, a different
     * question. The page itself refuses whoever may not read it.
     */
    public function testAStaffOnlyArticleStillHasItsPage(): void
    {
        $this->assertSame(
            '/s/abc',
            $this->service($this->article(Article::VISIBILITY_CHIEF, 'abc'))->pagePath(5)
        );
    }

    private function article(string $visibility, ?string $code): Article
    {
        return new Article(5, 'Inscriptions', $visibility, false, true, null, null, $code, 7, '2026-09-01', '2026-09-01', null, 11, Article::STATUS_PUBLISHED, '2026-09-01 10:00:00');
    }

    private function service(Article $article): ArticleShareSourceService
    {
        $articles = $this->createStub(ArticleService::class);
        $articles->method('findById')->willReturnCallback(static fn (int $id): ?Article => $id === $article->id ? $article : null);
        $articles->method('canEdit')->willReturnCallback(
            static fn (Article $a, Role $role, int $account): bool => $account === $a->createdBy
        );
        $articles->method('isSociallyShareable')->willReturnCallback(
            static fn (Article $a): bool => in_array($a->visibility, Article::SOCIALLY_SHAREABLE_VISIBILITIES, true)
        );
        $settings = $this->createStub(SettingService::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): mixed => $key === 'base_url' ? 'https://unite.example/' : null);

        return new ArticleShareSourceService($articles, $settings);
    }
}
