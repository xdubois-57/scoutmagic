<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Restore;

use Core\Security\EncryptionService;

/**
 * `storage_locations` as raw rows, for the one job that has to handle them
 * that way: carrying today's bindings across a restore (#832).
 *
 * Raw, and not through Core\Storage\Location\StorageLocationRepository,
 * on purpose. That repository decrypts the secret and hydrates a typed
 * configuration, and both can fail right after a restore — a key the
 * restore replaced, a type a newer backup wrote. Here the configuration is
 * the JSON it is stored as and the secret the ciphertext it is stored as:
 * a binding is moved from one row to another without ever being read.
 */
class StorageBindingRepository
{
    public function __construct(
        private \PDO $pdo,
        /**
         * Only to answer {@see isReadable()} — the one question about a
         * secret this class asks, and asked here because only a repository
         * calls EncryptionService (SECURITY.md, Encryption at rest). Null
         * when the caller only reads or moves rows.
         */
        private ?EncryptionService $encryption = null
    ) {
    }

    /**
     * Runs $work in one transaction: committed whole, or rolled back whole
     * when it throws. Joins one already open rather than nesting.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Whether this ciphertext opens with the keys the site now holds — a
     * portable restore installs the archive's. The plaintext is discarded:
     * the binding moves as ciphertext, never read.
     */
    public function isReadable(?string $secret): bool
    {
        if ($secret === null) {
            return true;
        }
        if ($this->encryption === null) {
            throw new \LogicException('Asking whether a secret is readable needs the encryption service.');
        }
        try {
            $this->encryption->decrypt($secret, 'storage_locations.secret');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{id: int, type: string, label: string, config: string, secret: ?string}>
     */
    public function rows(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, type, label, config, secret_encrypted FROM storage_locations ORDER BY id'
        );
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $secret = $row['secret_encrypted'] ?? null;
            if (is_resource($secret)) {
                $secret = stream_get_contents($secret);
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'label' => (string) $row['label'],
                'config' => (string) ($row['config'] ?? '{}'),
                'secret' => is_string($secret) && $secret !== '' ? $secret : null,
            ];
        }

        return $rows;
    }

    /**
     * Puts a binding back on an existing row. The recorded health is
     * cleared: it was measured against the binding being replaced.
     */
    public function rebind(int $id, string $label, string $config, ?string $secret, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_locations SET label = ?, config = ?, secret_encrypted = ?, reconciliation_note = ?,
                last_checked_at = NULL, last_check_ok = NULL, last_check_error = NULL
             WHERE id = ?'
        );
        $stmt->bindValue(1, $label);
        $stmt->bindValue(2, $config);
        $stmt->bindValue(3, $secret, $secret === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(4, $note, $note === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(5, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Inserts a location the restored database does not have, never as
     * the default — which location new content goes to is the backup's
     * decision, and it comes back with it. $id is kept when free, so that
     * whatever still names this location by its id keeps finding it.
     */
    public function insert(?int $id, string $type, string $label, string $config, ?string $secret, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO storage_locations (id, type, label, is_default, config, secret_encrypted, reconciliation_note,
                created_at)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->bindValue(1, $id, $id === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(2, $type);
        $stmt->bindValue(3, $label);
        $stmt->bindValue(4, $config);
        $stmt->bindValue(5, $secret, $secret === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(6, $note, $note === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(7, date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    /** Marks a restored location whose target nobody has confirmed. */
    public function flag(int $id, string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_locations SET reconciliation_note = ?,
                last_checked_at = NULL, last_check_ok = NULL, last_check_error = NULL
             WHERE id = ?'
        );
        $stmt->execute([$note, $id]);
    }
}
