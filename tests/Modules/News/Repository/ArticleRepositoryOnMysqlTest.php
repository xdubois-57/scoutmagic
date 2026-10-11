<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Repository;

use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Publication (issue #845) on the engine production runs.
 *
 * Two things here the engine decides. The column defaults stand in for a
 * data migration — an article written before the columns existed, or by
 * any INSERT that names neither, is « published » and dated by the server
 * — and ArticleRepository::liveCondition() compares a DATETIME column with
 * a bound string, which SQLite compares as text and MySQL/MariaDB as a
 * date.
 */
#[Group('database')]
final class ArticleRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ArticleRepository $repository;
    private int $authorId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new ArticleRepository($this->pdo);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', str_repeat('a', 64)]);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    /**
     * An existing article is online once the columns are added: the
     * defaults are the whole data migration.
     */
    public function testARowThatNamesNeitherColumnIsPublishedAndDatedByTheServer(): void
    {
        $this->pdo->prepare('INSERT INTO news_articles (title, visibility, created_by) VALUES (?, ?, ?)')
            ->execute(['Ancienne', Article::VISIBILITY_PUBLIC, $this->authorId]);
        $article = $this->repository->findById((int) $this->pdo->lastInsertId());

        $this->assertNotNull($article);
        $this->assertSame(Article::STATUS_PUBLISHED, $article->status);
        $this->assertNotNull($article->publishedAt, 'the engine fills published_at from its default');
        $this->assertSame(['Ancienne'], $this->titles($this->repository->findByVisibilities([Article::VISIBILITY_PUBLIC])));
    }

    public function testCreateWritesADraftWithNoDateWhateverTheDefaults(): void
    {
        $article = $this->repository->findById(
            $this->repository->create('Nouvelle', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId)
        );

        $this->assertNotNull($article);
        $this->assertSame(Article::STATUS_DRAFT, $article->status);
        $this->assertNull($article->publishedAt);
    }

    /**
     * The bound « now » against the DATETIME column, to the minute: online
     * at its time, not a minute before — and the lists ordered by it.
     */
    public function testTheLiveConditionComparesDatesOnThisEngine(): void
    {
        $early = $this->repository->create('Tôt', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $late = $this->repository->create('Tard', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->repository->create('Brouillon', Article::VISIBILITY_PUBLIC, false, null, null, $this->authorId);
        $this->repository->setPublication($early, Article::STATUS_PUBLISHED, '2026-10-20 08:00:00');
        $this->repository->setPublication($late, Article::STATUS_PUBLISHED, '2026-10-20 18:30:00');
        $visibilities = [Article::VISIBILITY_PUBLIC];

        $this->assertSame([], $this->titles($this->repository->findByVisibilities($visibilities, new \DateTimeImmutable('2026-10-20 07:59:00'))));
        $this->assertSame(['Tôt'], $this->titles($this->repository->findByVisibilities($visibilities, new \DateTimeImmutable('2026-10-20 08:00:00'))));

        $evening = new \DateTimeImmutable('2026-10-20 18:30:00');
        $this->assertSame(['Tard', 'Tôt'], $this->titles($this->repository->findByVisibilitiesPage($visibilities, 10, 0, $evening)));
        $this->assertSame(2, $this->repository->countByVisibilities($visibilities, $evening));
        $this->assertSame(['Tard'], $this->titles($this->repository->findLatestByVisibilities($visibilities, 1, $evening)));
    }

    /**
     * @param Article[] $articles
     * @return string[]
     */
    private function titles(array $articles): array
    {
        return array_map(static fn (Article $a): string => $a->title, $articles);
    }
}
