<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Security\EncryptionService;

class NotificationRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function create(int $userAccountId, ?int $memberId, string $typeId, string $title, string $body, ?string $url): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, member_id, type_id, title, body, url) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $memberId,
            $typeId,
            $this->encryption->encrypt($title),
            $this->encryption->encrypt($body),
            $url,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?NotificationRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Most recent notifications for an account, newest first — the
     * notification centre.
     *
     * @return NotificationRecord[]
     */
    public function findByUserAccountId(int $userAccountId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_account_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userAccountId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countUnread(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_account_id = ? AND read_at IS NULL');
        $stmt->execute([$userAccountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Most recent unread notification for an account — feeds the nav
     * notification indicator's "last one" preview (partials/
     * notification_dropdown.html.twig). Deliberately unread-only, not just
     * "most recent overall": the most recent notification can already be
     * read while an older one is still unread (e.g. the member followed a
     * direct link to a newer notification without visiting the centre),
     * and showing an already-read notification in a "pending" indicator
     * would misrepresent what's actually waiting for attention.
     */
    public function findLatestUnread(int $userAccountId): ?NotificationRecord
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_account_id = ? AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$userAccountId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function markRead(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = ? WHERE id = ? AND read_at IS NULL');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function markAllReadForUser(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET read_at = ? WHERE user_account_id = ? AND read_at IS NULL');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $userAccountId]);
    }

    /**
     * Retention purge (Configuration > Notifications, default 90 days,
     * Core\Notification\Task\PurgeNotificationsHandler) — only ever
     * touches READ notifications; an unread one is never purged
     * regardless of age, per module spec.
     */
    public function deleteReadOlderThan(\DateTimeInterface $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < ?');
        $stmt->execute([$cutoff->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationRecord
    {
        return new NotificationRecord(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            memberId: $row['member_id'] !== null ? (int) $row['member_id'] : null,
            typeId: (string) $row['type_id'],
            title: $this->encryption->decrypt($this->readBlob($row['title'])),
            body: $this->encryption->decrypt($this->readBlob($row['body'])),
            url: $row['url'] !== null ? (string) $row['url'] : null,
            readAt: $row['read_at'] !== null ? (string) $row['read_at'] : null,
            createdAt: (string) $row['created_at']
        );
    }

    private function readBlob(mixed $value): string
    {
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
