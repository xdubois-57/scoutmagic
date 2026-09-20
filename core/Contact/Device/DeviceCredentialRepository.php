<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\Device;

use Core\Service\DateInput;

/**
 * `device_credentials`, and the only layer that ever touches a secret's
 * hash.
 *
 * Nothing here returns a hash and nothing here logs one. A caller offers
 * a candidate secret and gets back a credential or null
 * ({@see self::findLiveMatching()}); it never gets the chance to compare
 * two hashes itself, which is how a comparison ends up non-constant-time.
 */
class DeviceCredentialRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return string The SHA-256 a secret is stored and compared as. A
     *         fast hash is as safe as bcrypt at 32 bytes of entropy, and
     *         this one is checked on an anonymous route a client polls
     *         every few minutes (SECURITY.md §2).
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function create(int $userAccountId, string $label, string $secret): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_credentials (user_account_id, label, secret_hash, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userAccountId,
            $label,
            self::hashSecret($secret),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The live credential of $userAccountId whose secret is $secret, or
     * null.
     *
     * Every candidate is compared with `hash_equals()` and the loop does
     * NOT stop early on a mismatch — an account has a handful of devices
     * at most, and a loop whose duration says how far down the list the
     * match was is a timing oracle for free.
     */
    public function findLiveMatching(int $userAccountId, string $secret): ?DeviceCredential
    {
        $candidate = self::hashSecret($secret);
        $match = null;

        foreach ($this->liveRowsFor($userAccountId) as $row) {
            if (hash_equals((string) $row['secret_hash'], $candidate)) {
                $match = $this->hydrate($row);
            }
        }

        return $match;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function liveRowsFor(int $userAccountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM device_credentials WHERE user_account_id = ? AND revoked_at IS NULL ORDER BY id'
        );
        $stmt->execute([$userAccountId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Every credential of one account, revoked ones included and last: a
     * revoked credential is evidence that the device existed, and the
     * screen keeps saying so.
     *
     * @return list<DeviceCredential>
     */
    public function findAllForAccount(int $userAccountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM device_credentials WHERE user_account_id = ?
             ORDER BY (revoked_at IS NOT NULL), id DESC'
        );
        $stmt->execute([$userAccountId]);

        return array_map(fn(array $row): DeviceCredential => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every credential of every account, for the superadmin's own view —
     * a feature that replicates personal data has to be visible whole,
     * not one account at a time.
     *
     * @return list<DeviceCredential>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM device_credentials ORDER BY (revoked_at IS NOT NULL), id DESC'
        );

        return array_map(
            fn(array $row): DeviceCredential => $this->hydrate($row),
            $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    public function findById(int $id): ?DeviceCredential
    {
        $stmt = $this->pdo->prepare('SELECT * FROM device_credentials WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function countLiveForAccount(int $userAccountId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM device_credentials WHERE user_account_id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$userAccountId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Revoking never deletes: the row is the trace that the device
     * existed, and the copy it already pulled down is still on it.
     * Idempotent — a second revocation leaves the first instant alone.
     */
    public function revoke(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_credentials SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    public function touchSync(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE device_credentials SET last_sync_at = ? WHERE id = ?');
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DeviceCredential
    {
        return new DeviceCredential(
            id: (int) $row['id'],
            userAccountId: (int) $row['user_account_id'],
            label: (string) $row['label'],
            createdAt: DateInput::fromStorage((string) $row['created_at']) ?? new \DateTimeImmutable(),
            lastSyncAt: DateInput::fromStorage($row['last_sync_at'] !== null ? (string) $row['last_sync_at'] : null),
            revokedAt: DateInput::fromStorage($row['revoked_at'] !== null ? (string) $row['revoked_at'] : null),
        );
    }
}
