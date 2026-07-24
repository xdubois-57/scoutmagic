<?php

declare(strict_types=1);

namespace Modules\News\Repository;

class ArticleRepository
{
    public function __construct(private \PDO $pdo)
    {
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
        $stmt = $this->pdo->query('SELECT * FROM news_articles ORDER BY created_at DESC');
        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findByVisibilities(array $visibilities): array
    {
        if ($visibilities === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) ORDER BY created_at DESC");
        $stmt->execute($visibilities);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Articles visible to a chief/admin management view: the given
     * visibilities, plus any direct_link article authored by $authorId
     * (module spec: "plus direct_link articles they authored").
     *
     * @param string[] $visibilities
     * @return Article[]
     */
    public function findForManager(array $visibilities, int $authorId): array
    {
        $placeholders = implode(',', array_fill(0, count($visibilities), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM news_articles WHERE visibility IN ({$placeholders}) OR (visibility = 'direct_link' AND created_by = ?) ORDER BY created_at DESC"
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
        int $createdBy
    ): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_articles (title, visibility, is_indexed, seo_keywords, seo_stop_date, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $visibility, $isIndexed ? 1 : 0, $seoKeywords, $seoStopDate, $createdBy, $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE news_articles SET title = ?, visibility = ?, is_indexed = ?, seo_keywords = ?, seo_stop_date = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $visibility, $isIndexed ? 1 : 0, $seoKeywords, $seoStopDate, date('Y-m-d H:i:s'), $id]);
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
            updatedAt: (string) $row['updated_at']
        );
    }
}
