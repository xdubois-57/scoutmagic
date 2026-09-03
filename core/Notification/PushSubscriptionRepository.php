<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Security\EncryptionService;

class PushSubscriptionRepository
{
    /**
     * A device is dropped after this many consecutive non-expiry failures
     * (network errors, 5xx, etc.) — a 404/410 deletes it immediately
     * regardless of this counter (see NotificationService::dispatchPush()).
     */
    public const FAILURE_THRESHOLD = 5;

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

    public function findById(int $id): ?PushSubscription
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByEndpoint(int $userAccountId, string $endpoint): ?PushSubscription
    {
        $blindIndex = $this->encryption->blindIndex($endpoint, 'push_endpoint');
        $stmt = $this->pdo->prepare(
            'SELECT * FROM push_subscriptions WHERE user_account_id = ? AND endpoint_blind_index = ?'
        );
        $stmt->execute([$userAccountId, $blindIndex]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        int $userAccountId,
        string $endpoint,
        string $authKey,
        string $p256dhKey,
        ?string $deviceLabel = null
    ): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO push_subscriptions (user_account_id, endpoint, endpoint_blind_index, auth_key, p256dh_key, '
                . 'device_label)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $this->encryption->encrypt($endpoint, 'push_subscriptions.endpoint'),
            $this->encryption->blindIndex($endpoint, 'push_endpoint'),
            $this->encryption->encrypt($authKey, 'push_subscriptions.auth_key'),
            $this->encryption->encrypt($p256dhKey, 'push_subscriptions.p256dh_key'),
            $deviceLabel,
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
        $blindIndex = $this->encryption->blindIndex($endpoint, 'push_endpoint');
        $stmt = $this->pdo->prepare(
            'DELETE FROM push_subscriptions WHERE user_account_id = ? AND endpoint_blind_index = ?'
        );
        $stmt->execute([$userAccountId, $blindIndex]);
    }

    public function deleteAllForUser(int $userAccountId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_subscriptions WHERE user_account_id = ?');
        $stmt->execute([$userAccountId]);
    }

    /**
     * A push actually reached the endpoint — resets the failure streak.
     */
    public function recordSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE push_subscriptions SET last_success_at = ?, failure_count = 0 WHERE id = '
            . '?');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * A non-expiry failure (network error, 5xx, ...) — bumps the streak,
     * and drops the subscription once it crosses FAILURE_THRESHOLD. A
     * 404/410 (subscription gone) is NOT routed through this — that's an
     * immediate delete() from the caller, no threshold involved.
     */
    public function recordFailure(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE push_subscriptions SET failure_count = failure_count + 1 WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('SELECT failure_count FROM push_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();

        if ($count !== false && (int) $count >= self::FAILURE_THRESHOLD) {
            $this->delete($id);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PushSubscription
    {
        return new PushSubscription(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            endpoint: $this->encryption->decrypt($this->readBlob($row['endpoint']), 'push_subscriptions.endpoint'),
            authKey: $this->encryption->decrypt($this->readBlob($row['auth_key']), 'push_subscriptions.auth_key'),
            p256dhKey: $this->encryption->decrypt($this->readBlob($row['p256dh_key']), 'push_subscriptions.p256dh_key'),
            deviceLabel: $row['device_label'] !== null ? (string) $row['device_label'] : null,
            createdAt: (string) $row['created_at'],
            lastSuccessAt: $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
            failureCount: (int) $row['failure_count']
        );
    }

    private function readBlob(mixed $value): string
    {
        return is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
