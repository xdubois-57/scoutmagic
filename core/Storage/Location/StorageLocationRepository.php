<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Security\EncryptionService;
use Core\Storage\Location\Config\LocationConfig;

/**
 * The `storage_locations` table.
 *
 * Two things are true of this class and of nothing else in the codebase,
 * and both are deliberate:
 *
 * - **It is the only reader of the secret.** {@see getSecret()} decrypts;
 *   {@see StorageLocation} carries `secretConfigured` and nothing more.
 *   A DTO that could hold a credential is a credential that reaches a
 *   template, a JSON body or a support package the first time somebody
 *   dumps an object while debugging.
 * - **It is where the uniqueness of the default is enforced**, in
 *   {@see setDefault()}, inside a transaction. A `CHECK` or a trigger for
 *   « at most one TRUE row » has no clean, portable expression in this
 *   schema style, and two defaults — or none — is a state a read must
 *   never observe.
 */
class StorageLocationRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return list<StorageLocation>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM storage_locations ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }

        return array_values(array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function findById(int $id): ?StorageLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByLabel(string $label): ?StorageLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM storage_locations WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * The decrypted secret, for {@see Backend\StorageBackendFactory} only —
     * never exposed on the DTO, which carries `secretConfigured: bool`.
     */
    public function getSecret(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT secret_encrypted FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        // Read as a row rather than through fetchColumn(): a BLOB comes
        // back as a stream resource on some drivers and as a string on
        // others, and fetchColumn()'s declared `string|false` hides the
        // first case from static analysis — which is how the resource
        // branch below reads as dead code while being the one that runs.
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        $encrypted = is_array($row) ? ($row[0] ?? null) : null;
        if ($encrypted === null || $encrypted === false) {
            return null;
        }
        if (is_resource($encrypted)) {
            $encrypted = stream_get_contents($encrypted);
        }
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        return $this->encryption->decrypt($encrypted, 'storage_locations.secret');
    }

    /**
     * The very first location ever created — any type, any caller —
     * becomes the default automatically. Every later one starts non-default
     * until an administrator explicitly promotes it.
     */
    public function create(
        StorageLocationType $type,
        string $label,
        LocationConfig $config,
        ?string $secret
    ): int {
        $isFirst = (int) $this->pdo->query('SELECT COUNT(*) FROM storage_locations')->fetchColumn() === 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO storage_locations (type, label, is_default, config, secret_encrypted, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $type->value,
            $label,
            $isFirst ? 1 : 0,
            self::encodeConfig($config),
            $secret !== null && $secret !== ''
                ? $this->encryption->encrypt($secret, 'storage_locations.secret')
                : null,
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * $secret null or empty keeps the existing one unchanged — the « leave
     * blank to keep » convention every credential field in this codebase
     * uses, and the reason an administrator can correct a bucket name
     * without re-typing a key they do not have in front of them.
     */
    public function update(int $id, string $label, LocationConfig $config, ?string $secret): void
    {
        if ($secret !== null && $secret !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE storage_locations SET label = ?, config = ?, secret_encrypted = ? WHERE id = ?'
            );
            $stmt->execute([
                $label,
                self::encodeConfig($config),
                $this->encryption->encrypt($secret, 'storage_locations.secret'),
                $id,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare('UPDATE storage_locations SET label = ?, config = ? WHERE id = ?');
        $stmt->execute([$label, self::encodeConfig($config), $id]);
    }

    /**
     * Promotes $id to the sole default, demoting whichever held it before
     * — in a transaction, so a read in between never observes zero or two.
     */
    public function setDefault(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE storage_locations SET is_default = 0');
            $stmt = $this->pdo->prepare('UPDATE storage_locations SET is_default = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The current default, falling back to the first created when none is
     * flagged — never null while at least one location exists.
     */
    public function findDefault(): ?StorageLocation
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM storage_locations WHERE is_default = 1 ORDER BY id ASC LIMIT 1'
        );
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        if ($row !== false) {
            return $this->hydrate($row);
        }

        $stmt = $this->pdo->query('SELECT * FROM storage_locations ORDER BY id ASC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function recordCheckResult(int $id, bool $ok, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_locations SET last_checked_at = ?, last_check_ok = ?, last_check_error = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $ok ? 1 : 0, $error, $id]);
    }

    private static function encodeConfig(LocationConfig $config): string
    {
        $json = json_encode($config->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '{}';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StorageLocation
    {
        $type = StorageLocationType::tryFrom((string) $row['type']);
        if ($type === null) {
            // A row written by a newer version of the site, read by an
            // older one. Refusing is the honest answer: pretending it is a
            // local folder would point a consumer at the wrong disk.
            throw new StorageLocationException(sprintf(
                "L'emplacement « %s » utilise un type de stockage que cette version du site ne connaît pas.",
                (string) $row['label']
            ));
        }

        $raw = json_decode((string) ($row['config'] ?? '{}'), true);

        return new StorageLocation(
            id: (int) $row['id'],
            type: $type,
            label: (string) $row['label'],
            isDefault: (bool) $row['is_default'],
            config: $type->configFromArray(is_array($raw) ? $raw : []),
            secretConfigured: $row['secret_encrypted'] !== null,
            lastCheckedAt: $row['last_checked_at'] !== null ? (string) $row['last_checked_at'] : null,
            lastCheckOk: $row['last_check_ok'] !== null ? (bool) $row['last_check_ok'] : null,
            lastCheckError: $row['last_check_error'] !== null ? (string) $row['last_check_error'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
