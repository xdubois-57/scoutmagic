<?php

declare(strict_types=1);

namespace Modules\News\Repository;

class FormRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?NewsForm
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_forms WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByArticleId(int $articleId): ?NewsForm
    {
        $stmt = $this->pdo->prepare('SELECT * FROM news_forms WHERE news_article_id = ?');
        $stmt->execute([$articleId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        int $articleId,
        string $access,
        string $responseLimit,
        ?string $opensAt,
        ?string $closesAt,
        bool $isForceClosed,
        string $responseRoleMin,
        bool $dailyDigestEnabled,
        ?int $financeAccountId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_forms (news_article_id, access, response_limit, opens_at, closes_at, is_force_closed, response_role_min, daily_digest_enabled, finance_account_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $articleId, $access, $responseLimit, $opensAt, $closesAt,
            $isForceClosed ? 1 : 0, $responseRoleMin, $dailyDigestEnabled ? 1 : 0, $financeAccountId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $access,
        string $responseLimit,
        ?string $opensAt,
        ?string $closesAt,
        bool $isForceClosed,
        string $responseRoleMin,
        bool $dailyDigestEnabled,
        ?int $financeAccountId
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE news_forms SET access = ?, response_limit = ?, opens_at = ?, closes_at = ?, is_force_closed = ?, response_role_min = ?, daily_digest_enabled = ?, finance_account_id = ? WHERE id = ?'
        );
        $stmt->execute([
            $access, $responseLimit, $opensAt, $closesAt,
            $isForceClosed ? 1 : 0, $responseRoleMin, $dailyDigestEnabled ? 1 : 0, $financeAccountId, $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM news_forms WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function markDigestSent(int $id, string $sentAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE news_forms SET last_digest_sent_at = ? WHERE id = ?');
        $stmt->execute([$sentAt, $id]);
    }

    /**
     * @return NewsForm[] every form with the daily digest enabled — Task\SendResponseDigestHandler's iteration set.
     */
    public function findAllWithDigestEnabled(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM news_forms WHERE daily_digest_enabled = 1');
        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NewsForm
    {
        return new NewsForm(
            id: (int) $row['id'],
            newsArticleId: (int) $row['news_article_id'],
            access: (string) $row['access'],
            responseLimit: (string) $row['response_limit'],
            opensAt: $row['opens_at'] !== null ? (string) $row['opens_at'] : null,
            closesAt: $row['closes_at'] !== null ? (string) $row['closes_at'] : null,
            isForceClosed: (bool) $row['is_force_closed'],
            responseRoleMin: (string) $row['response_role_min'],
            dailyDigestEnabled: (bool) $row['daily_digest_enabled'],
            lastDigestSentAt: $row['last_digest_sent_at'] !== null ? (string) $row['last_digest_sent_at'] : null,
            financeAccountId: $row['finance_account_id'] !== null ? (int) $row['finance_account_id'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
