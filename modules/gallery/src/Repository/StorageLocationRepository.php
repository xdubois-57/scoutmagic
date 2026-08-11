<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

use Core\Security\EncryptionService;
use Modules\Gallery\Service\GalleryException;

class StorageLocationRepository
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * @return StorageLocation[]
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM gallery_storage_locations ORDER BY id ASC');
        return $stmt !== false ? array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)) : [];
    }

    public function findById(int $id): ?StorageLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery_storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByLabel(string $label): ?StorageLocation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gallery_storage_locations WHERE label = ?');
        $stmt->execute([$label]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @return string|null the decrypted secret key, for internal use by
     *                      Service\Storage\StorageBackendFactory only —
     *                      never exposed on the StorageLocation DTO itself
     *                      (which only carries secretConfigured: bool).
     */
    public function getSecret(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT secret_key_encrypted FROM gallery_storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        $encrypted = $stmt->fetchColumn();
        if ($encrypted === false || $encrypted === null) {
            return null;
        }
        if (is_resource($encrypted)) {
            $encrypted = stream_get_contents($encrypted);
        }
        return $this->encryption->decrypt((string) $encrypted);
    }

    /**
     * The very first location ever created (any type, any caller) becomes
     * the default automatically — every subsequent one starts as non-default
     * until an admin explicitly promotes it via setDefault().
     */
    public function create(
        string $type,
        string $label,
        ?string $subdir,
        ?string $s3Provider,
        ?string $s3Endpoint,
        ?string $s3Region,
        ?string $s3Bucket,
        ?string $s3AccessKey,
        ?string $s3PublicUrl,
        ?string $secretKey
    ): int {
        $isFirst = (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_storage_locations')->fetchColumn() === 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO gallery_storage_locations
                (type, label, is_default, subdir, s3_provider, s3_endpoint, s3_region, s3_bucket, s3_access_key, s3_public_url, secret_key_encrypted, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $type, $label, $isFirst ? 1 : 0, $subdir, $s3Provider, $s3Endpoint, $s3Region, $s3Bucket, $s3AccessKey, $s3PublicUrl,
            $secretKey !== null && $secretKey !== '' ? $this->encryption->encrypt($secretKey) : null,
            date('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Promotes $id to the sole default location, demoting whichever one
     * held it before — wrapped in a transaction so a read in between never
     * observes zero or two default locations.
     */
    public function setDefault(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE gallery_storage_locations SET is_default = 0');
            $stmt = $this->pdo->prepare('UPDATE gallery_storage_locations SET is_default = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The current default, falling back to the first-created location when
     * none is explicitly flagged yet (installs with locations predating the
     * is_default column) — never null when at least one location exists.
     */
    public function findDefault(): ?StorageLocation
    {
        $stmt = $this->pdo->query('SELECT * FROM gallery_storage_locations WHERE is_default = 1 ORDER BY id ASC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        if ($row !== false) {
            return $this->hydrate($row);
        }

        $stmt = $this->pdo->query('SELECT * FROM gallery_storage_locations ORDER BY id ASC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * $secretKey null/empty keeps the existing secret unchanged (same
     * "leave blank to keep" convention as the old singleton S3SecretRepository).
     */
    public function update(
        int $id,
        string $label,
        ?string $subdir,
        ?string $s3Provider,
        ?string $s3Endpoint,
        ?string $s3Region,
        ?string $s3Bucket,
        ?string $s3AccessKey,
        ?string $s3PublicUrl,
        ?string $secretKey
    ): void {
        if ($secretKey !== null && $secretKey !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE gallery_storage_locations
                 SET label = ?, subdir = ?, s3_provider = ?, s3_endpoint = ?, s3_region = ?, s3_bucket = ?, s3_access_key = ?, s3_public_url = ?, secret_key_encrypted = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $label, $subdir, $s3Provider, $s3Endpoint, $s3Region, $s3Bucket, $s3AccessKey, $s3PublicUrl,
                $this->encryption->encrypt($secretKey), $id,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE gallery_storage_locations
             SET label = ?, subdir = ?, s3_provider = ?, s3_endpoint = ?, s3_region = ?, s3_bucket = ?, s3_access_key = ?, s3_public_url = ?
             WHERE id = ?'
        );
        $stmt->execute([$label, $subdir, $s3Provider, $s3Endpoint, $s3Region, $s3Bucket, $s3AccessKey, $s3PublicUrl, $id]);
    }

    /**
     * @throws GalleryException when still referenced by at least one album
     *                           — a clean, user-facing message instead of a
     *                           raw FK-constraint SQL error.
     */
    public function delete(int $id): void
    {
        if ($this->countAlbumsUsing($id) > 0) {
            throw new GalleryException('Cette location est encore utilisée par au moins un album — impossible de la supprimer.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM gallery_storage_locations WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function countAlbumsUsing(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gallery_albums WHERE storage_location_id = ?');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    public function recordCheckResult(int $id, bool $ok, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE gallery_storage_locations SET last_checked_at = ?, last_check_ok = ?, last_check_error = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $ok ? 1 : 0, $error, $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StorageLocation
    {
        return new StorageLocation(
            id: (int) $row['id'],
            type: (string) $row['type'],
            label: (string) $row['label'],
            isDefault: (bool) $row['is_default'],
            subdir: $row['subdir'] !== null ? (string) $row['subdir'] : null,
            s3Provider: $row['s3_provider'] !== null ? (string) $row['s3_provider'] : null,
            s3Endpoint: $row['s3_endpoint'] !== null ? (string) $row['s3_endpoint'] : null,
            s3Region: $row['s3_region'] !== null ? (string) $row['s3_region'] : null,
            s3Bucket: $row['s3_bucket'] !== null ? (string) $row['s3_bucket'] : null,
            s3AccessKey: $row['s3_access_key'] !== null ? (string) $row['s3_access_key'] : null,
            s3PublicUrl: $row['s3_public_url'] !== null ? (string) $row['s3_public_url'] : null,
            secretConfigured: $row['secret_key_encrypted'] !== null,
            lastCheckedAt: $row['last_checked_at'] !== null ? (string) $row['last_checked_at'] : null,
            lastCheckOk: $row['last_check_ok'] !== null ? (bool) $row['last_check_ok'] : null,
            lastCheckError: $row['last_check_error'] !== null ? (string) $row['last_check_error'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
