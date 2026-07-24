<?php

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\Article;
use PHPUnit\Framework\TestCase;

class ArticleTest extends TestCase
{
    private function build(bool $isIndexed, string $visibility, ?string $seoStopDate): Article
    {
        return new Article(1, 'Titre', $visibility, false, $isIndexed, null, $seoStopDate, null, 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00');
    }

    public function testNotIndexedIsNeverEffectivelyIndexed(): void
    {
        $this->assertFalse($this->build(false, Article::VISIBILITY_PUBLIC, null)->isEffectivelyIndexed());
    }

    public function testIndexedPublicArticleWithNoStopDateIsIndexed(): void
    {
        $this->assertTrue($this->build(true, Article::VISIBILITY_PUBLIC, null)->isEffectivelyIndexed());
    }

    public function testDirectLinkIsNeverEffectivelyIndexedEvenIfIsIndexedIsTrue(): void
    {
        $this->assertFalse($this->build(true, Article::VISIBILITY_DIRECT_LINK, null)->isEffectivelyIndexed());
    }

    public function testPastStopDateMakesItNoindex(): void
    {
        $article = $this->build(true, Article::VISIBILITY_PUBLIC, '2020-01-01');
        $this->assertFalse($article->isEffectivelyIndexed(new \DateTimeImmutable('2026-01-01')));
    }

    public function testFutureStopDateStillIndexed(): void
    {
        $article = $this->build(true, Article::VISIBILITY_PUBLIC, '2030-01-01');
        $this->assertTrue($article->isEffectivelyIndexed(new \DateTimeImmutable('2026-01-01')));
    }
}
