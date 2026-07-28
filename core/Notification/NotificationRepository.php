<?php

declare(strict_types=1);

namespace Core\Notification;

class NotificationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $userAccountId, string $title, string $body, ?string $url): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_account_id, title, body, url) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userAccountId, $title, $body, $url]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Most recent notifications for an account, newest first — for a
     * future in-app notification center.
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

    public function markRead(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationRecord
    {
        return new NotificationRecord(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            title: (string) $row['title'],
            body: (string) $row['body'],
            url: $row['url'] !== null ? (string) $row['url'] : null,
            isRead: (bool) $row['is_read'],
            createdAt: (string) $row['created_at']
        );
    }
}
