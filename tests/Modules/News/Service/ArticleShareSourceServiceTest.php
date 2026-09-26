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

    public function testAnArticleTheCallerMayNotEditIsNotThere(): void
    {
        $service = $this->service($this->article(Article::VISIBILITY_PUBLIC, 'abc'));

        $this->assertNull($service->describe(5, 'chief', 8));
        $this->assertNull($service->describe(6, 'chief', 7));
    }

    private function article(string $visibility, ?string $code): Article
    {
        return new Article(5, 'Inscriptions', $visibility, false, true, null, null, $code, 7, '2026-09-01', '2026-09-01', null, 11);
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
