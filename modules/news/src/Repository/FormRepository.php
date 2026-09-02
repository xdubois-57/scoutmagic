<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
        ?int $financeAccountId,
        bool $issuesTicket = false,
        ?string $eventDate = null,
        ?string $eventLocation = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO news_forms (news_article_id, access, response_limit, opens_at, closes_at, is_force_closed, response_role_min, daily_digest_enabled, finance_account_id, issues_ticket, event_date, event_location)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $articleId, $access, $responseLimit, $opensAt, $closesAt,
            $isForceClosed ? 1 : 0, $responseRoleMin, $dailyDigestEnabled ? 1 : 0, $financeAccountId,
            $issuesTicket ? 1 : 0, $eventDate, $eventLocation,
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
        ?int $financeAccountId,
        bool $issuesTicket = false,
        ?string $eventDate = null,
        ?string $eventLocation = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE news_forms SET access = ?, response_limit = ?, opens_at = ?, closes_at = ?, is_force_closed = ?, response_role_min = ?, daily_digest_enabled = ?, finance_account_id = ?, issues_ticket = ?, event_date = ?, event_location = ? WHERE id = ?'
        );
        $stmt->execute([
            $access, $responseLimit, $opensAt, $closesAt,
            $isForceClosed ? 1 : 0, $responseRoleMin, $dailyDigestEnabled ? 1 : 0, $financeAccountId,
            $issuesTicket ? 1 : 0, $eventDate, $eventLocation, $id,
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
     * @return NewsForm[] every form delivering a ticket — the door
     *                    screen's candidate set, narrowed afterwards to
     *                    those that actually have a booking.
     */
    public function findAllIssuingTickets(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM news_forms WHERE issues_ticket = 1 ORDER BY id DESC');

        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    /**
     * Whether any form at all delivers a ticket — the one question the
     * conditional « Scanner un billet » menu entry asks
     * (Menu\NewsMenuEntryProvider). A unit that never runs a ticketed
     * event must not carry a dead entry in its menu, and that is checked
     * on every menu build, so it is a bounded EXISTS rather than a list.
     */
    public function anyFormIssuesTickets(): bool
    {
        $stmt = $this->pdo->query('SELECT 1 FROM news_forms WHERE issues_ticket = 1 LIMIT 1');

        return $stmt !== false && $stmt->fetchColumn() !== false;
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
            issuesTicket: (bool) $row['issues_ticket'],
            eventDate: ($row['event_date'] ?? null) !== null ? (string) $row['event_date'] : null,
            eventLocation: ($row['event_location'] ?? null) !== null ? (string) $row['event_location'] : null,
            lastDigestSentAt: $row['last_digest_sent_at'] !== null ? (string) $row['last_digest_sent_at'] : null,
            financeAccountId: $row['finance_account_id'] !== null ? (int) $row['finance_account_id'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
