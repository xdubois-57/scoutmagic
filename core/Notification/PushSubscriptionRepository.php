<?php

declare(strict_types=1);

namespace Core\Notification;

use Core\Security\EncryptionService;

class PushSubscriptionRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return PushSubscription[]
     */
    public function findByUserAccountId(int $userAccountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_subscriptions WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findByEndpoint(int $userAccountId, string $endpoint): ?PushSubscription
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_subscriptions WHERE user_account_id = ? AND endpoint = ?');
        $stmt->execute([$userAccountId, $endpoint]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(int $userAccountId, string $endpoint, string $authKey, string $p256dhKey): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO push_subscriptions (user_account_id, endpoint, auth_key, p256dh_key) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $endpoint,
            $this->encryption->encrypt($authKey),
            $this->encryption->encrypt($p256dhKey),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteByEndpoint(int $userAccountId, string $endpoint): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE user_account_id = ? AND endpoint = ?');
        $stmt->execute([$userAccountId, $endpoint]);
    }

    public function deleteAllForUser(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PushSubscription
    {
        $authKey = $row['auth_key'];
        if (is_resource($authKey)) {
            $authKey = stream_get_contents($authKey);
        }
        $p256dhKey = $row['p256dh_key'];
        if (is_resource($p256dhKey)) {
            $p256dhKey = stream_get_contents($p256dhKey);
        }

        return new PushSubscription(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            endpoint: (string) $row['endpoint'],
            authKey: $this->encryption->decrypt((string) $authKey),
            p256dhKey: $this->encryption->decrypt((string) $p256dhKey),
            createdAt: (string) $row['created_at']
        );
    }
}
