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
        // created_at from PHP rather than the column's DEFAULT
        // CURRENT_TIMESTAMP: the notification list groups rows into
        // "Aujourd'hui" / "Hier" / a date by comparing this value against
        // PHP's own idea of today (Http\Controller\NotificationController),
        // and the retention purge cuts on it the same way — a notification
        // written on a different clock lands under the wrong heading for
        // the first hours of every day.
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, member_id, type_id, title, body, url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $memberId,
            $typeId,
            $this->encryption->encrypt($title, 'notifications.title'),
            $this->encryption->encrypt($body, 'notifications.body'),
            $url,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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

    /**
     * The unread notifications of one type, as just their routing facts —
     * url and creation time, never title/body (both encrypted; a caller
     * counting mentions per group has no business decrypting anything).
     * The groups module's homepage summary filters these by the group id
     * embedded in the url and by each group's own last-read time, so a
     * mention stops counting once its group has actually been opened.
     *
     * @return array<int, array{url: ?string, created_at: string}>
     */
    public function findUnreadOfType(int $userAccountId, string $typeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT url, created_at FROM notifications
             WHERE user_account_id = ? AND type_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userAccountId, $typeId]);

        return array_map(
            static fn(array $row) => [
                'url' => $row['url'] !== null ? (string) $row['url'] : null,
                'created_at' => (string) $row['created_at'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
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
    /**
     * The most recent unread notifications, newest first — what the
     * bell's own dropdown shows.
     *
     * Several rather than one: the panel already announces the count
     * ("3 notifications non lues") and used to list a single row under
     * it, which reads as the other two having gone missing. Bounded
     * because a dropdown is a preview and /notifications is the list.
     *
     * @return NotificationRecord[]
     */
    public function findRecentUnread(int $userAccountId, int $limit = 5): array
    {
        // Interpolated, never bound: an int this class computes, and
        // MySQL refuses a bound parameter in LIMIT with emulation off —
        // the same rule the module repositories follow.
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE user_account_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userAccountId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
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
            title: $this->encryption->decrypt($this->readBlob($row['title']), 'notifications.title'),
            body: $this->encryption->decrypt($this->readBlob($row['body']), 'notifications.body'),
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
