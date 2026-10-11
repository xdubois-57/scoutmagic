<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Repository;

class ArticleRepository
{
    /**
     * The sort key every list uses — the date an article is shown with
     * (Repository\Article::displayDate()): its publication date, or its
     * creation date while it is a draft.
     */
    private const DISPLAY_DATE_SQL = "CASE WHEN status = 'draft' OR published_at IS NULL THEN created_at "
        . 'ELSE published_at END';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * « Is it online? », in SQL — the one spelling of it (issue #845),
     * the twin of Service\ArticleService::isLive(). Online means
     * published and dated now or earlier: an article programmed for later
     * appears at its time by this test alone, with no task flipping
     * anything.
     *
     * One `?` to bind: the current instant, as `Y-m-d H:i:s`. Taken from
     * PHP rather than the database's NOW() so a test can stand at a
     * chosen moment and the two clocks never disagree.
     *
     * @param string $alias the table alias the condition qualifies, '' for none
     */
    public static function liveCondition(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return "({$prefix}status = 'published' AND {$prefix}published_at IS NOT NULL "
            . "AND {$prefix}published_at <= ?)";
    }

    private static function now(?\DateTimeImmutable $now): string
    {
        return ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function findById(int $id): ?Article
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_articles WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return Article[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM news_articles ORDER BY ' . self::DISPLAY_DATE_SQL . ' DESC');
        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * The ONLINE articles in $visibilities, most recently published first.
     *
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findByVisibilities(array $visibilities, ?\DateTimeImmutable $now = null): array
    {
        if ($visibilities === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) AND "
            . self::liveCondition() . ' ORDER BY published_at DESC');
        $stmt->execute([...$visibilities, self::now($now)]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * One page of findByVisibilities(), paginated in SQL — the public
     * /news list grows by every article ever published, and rendering it
     * whole also cost one form lookup per card.
     *
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findByVisibilitiesPage(
        array $visibilities,
        int $limit,
        int $offset,
        ?\DateTimeImmutable $now = null
    ): array {
        if ($visibilities === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) AND " . self::liveCondition()
                . ' ORDER BY published_at DESC LIMIT ? OFFSET ?'
        );
        foreach (array_values($visibilities) as $index => $visibility) {
            $stmt->bindValue($index + 1, $visibility);
        }
        $stmt->bindValue(count($visibilities) + 1, self::now($now));
        $stmt->bindValue(count($visibilities) + 2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(count($visibilities) + 3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param string[] $visibilities
     */
    public function countByVisibilities(array $visibilities, ?\DateTimeImmutable $now = null): int
    {
        if ($visibilities === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM news_articles WHERE visibility IN ({$placeholders}) AND "
            . self::liveCondition());
        $stmt->execute([...$visibilities, self::now($now)]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The $limit most recent articles in $visibilities —
     * Core\Module\HomeNewsProvider (homepage news column). The caller
     * decides the set from the reader's role
     * (Service\ArticleService::listableVisibilities()); this used to be
     * hardcoded to `public` alone, which left an `identified` article
     * reachable by URL and advertised nowhere.
     *
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findLatestByVisibilities(
        array $visibilities,
        int $limit,
        ?\DateTimeImmutable $now = null
    ): array {
        if ($visibilities === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) AND " . self::liveCondition()
                . ' ORDER BY published_at DESC LIMIT ?'
        );
        foreach (array_values($visibilities) as $index => $visibility) {
            $stmt->bindValue($index + 1, $visibility);
        }
        $stmt->bindValue(count($visibilities) + 1, self::now($now));
        $stmt->bindValue(count($visibilities) + 2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Articles visible to a chief/admin management view: the given
     * visibilities, plus any direct_link article authored by $authorId
     * (module spec: "plus direct_link articles they authored").
     *
     * Every state — drafts and scheduled articles are what this list is
     * for — sorted on the date each card shows (DISPLAY_DATE_SQL).
     *
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findForManager(array $visibilities, int $authorId): array
    {
        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) OR (visibility = 'direct_link' AND "
                . 'created_by = ?) ORDER BY ' . self::DISPLAY_DATE_SQL . ' DESC'
        );
        $stmt->execute([...$visibilities, $authorId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function create(
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        int $createdBy,
        ?string $summary = null,
        ?int $imageFileId = null
    ): int {
        $now = date('Y-m-d H:i:s');
        // Born a draft, with no publication date (issue #845) — written
        // here rather than left to the column defaults, which exist only
        // to date the articles that predate the status.
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_articles (title, summary, image_file_id, visibility, is_indexed, seo_keywords, '
                . 'seo_stop_date, status, published_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)'
        );
        $stmt->execute([$title, $summary, $imageFileId, $visibility, $isIndexed ? 1 : 0, $seoKeywords, $seoStopDate,
            Article::STATUS_DRAFT, $createdBy, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * $imageFileId = null means "keep the existing image" (the editor only
     * sends a new file when the author actually replaces it) — never
     * wipes a mandatory field just because this particular save didn't
     * re-upload anything, same "null means unchanged" convention as
     * Modules\Finance\Repository\AccountRepository::update().
     */
    public function update(
        int $id,
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        ?string $summary = null,
        ?int $imageFileId = null
    ): void {
        if ($imageFileId === null) {
            $existing = $this->findById($id);
            $imageFileId = $existing?->imageFileId;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE news_articles SET title = ?, summary = ?, image_file_id = ?, visibility = ?, is_indexed = ?, '
                . 'seo_keywords = ?, seo_stop_date = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $summary, $imageFileId, $visibility, $isIndexed ? 1 : 0, $seoKeywords, $seoStopDate,
            date('Y-m-d H:i:s'), $id]);
    }

    /**
     * Writes the publication state — Service\ArticleService decides what
     * the two values are, this only stores them.
     */
    public function setPublication(int $id, string $status, ?string $publishedAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_articles SET status = ?, published_at = ?, updated_at = ? '
            . 'WHERE id = ?');
        $stmt->execute([$status, $publishedAt, date('Y-m-d H:i:s'), $id]);
    }

    public function setHasForm(int $id, bool $hasForm): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_articles SET has_form = ? WHERE id = ?');
        $stmt->execute([$hasForm ? 1 : 0, $id]);
    }

    public function setShortUrlCode(int $id, string $code): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_articles SET short_url_code = ? WHERE id = ?');
        $stmt->execute([$code, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM news_articles WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Article
    {
        return new Article(
            id: (int) $row['id'],
            title: (string) $row['title'],
            visibility: (string) $row['visibility'],
            hasForm: (bool) $row['has_form'],
            isIndexed: (bool) $row['is_indexed'],
            seoKeywords: $row['seo_keywords'] !== null ? (string) $row['seo_keywords'] : null,
            seoStopDate: $row['seo_stop_date'] !== null ? (string) $row['seo_stop_date'] : null,
            shortUrlCode: $row['short_url_code'] !== null ? (string) $row['short_url_code'] : null,
            createdBy: (int) $row['created_by'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            summary: $row['summary'] !== null ? (string) $row['summary'] : null,
            imageFileId: $row['image_file_id'] !== null ? (int) $row['image_file_id'] : null,
            status: (string) ($row['status'] ?? Article::STATUS_PUBLISHED),
            publishedAt: isset($row['published_at']) ? (string) $row['published_at'] : null
        );
    }
}
