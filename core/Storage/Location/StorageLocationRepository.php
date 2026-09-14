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
        // Counted and inserted in one transaction, and the count takes a
        // WRITE lock. Two callers creating different locations on an
        // empty installation would otherwise both see zero rows and both
        // insert `is_default = 1` — the UNIQUE index on the label does
        // not catch it, because their labels differ, and two defaults is
        // a state every read of this table is written never to see.
        //
        // A transaction alone would not be enough either: under
        // REPEATABLE READ a plain SELECT reads a snapshot and takes no
        // lock at all, so both would still count zero. `FOR UPDATE` on an
        // empty table takes the gap lock that makes the second caller
        // wait for the first to commit. The cost is a table-wide lock on
        // an action an administrator performs by hand, a few times in the
        // life of a site.
        //
        // SQLite, which some of the tests run on, has no `FOR UPDATE` and
        // needs none — it serialises writers itself — so the clause is
        // added only for the engines that have it.
        $this->pdo->beginTransaction();
        try {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM storage_locations' . $this->forUpdate());
            $count->execute();
            $isFirst = (int) $count->fetchColumn() === 0;

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
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
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
            $this->pdo->prepare('UPDATE storage_locations SET is_default = 0')->execute();
            $stmt = $this->pdo->prepare('UPDATE storage_locations SET is_default = 1 WHERE id = ?');
            $stmt->execute([$id]);

            // Promoted nothing, having demoted everything. An id that
            // matches no row would otherwise leave the installation with
            // ZERO defaults — the other half of the state this
            // transaction exists to prevent, and the easier one to miss
            // because the statement itself succeeds.
            if ($stmt->rowCount() === 0 && $this->findByIdWithin($id) === null) {
                throw new StorageLocationException(
                    'Cet emplacement de stockage n\'existe plus — la page a peut-être été rouverte après sa '
                        . 'suppression.'
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Does this row exist, read inside the open transaction?
     *
     * `rowCount()` alone cannot answer: MySQL reports zero affected rows
     * for an UPDATE that matched a row already holding the value, so a
     * re-promotion of the current default is indistinguishable from an id
     * that matches nothing.
     */
    private function findByIdWithin(int $id): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM storage_locations WHERE id = ?');
        $stmt->execute([$id]);
        $found = $stmt->fetchColumn();

        return $found === false ? null : (int) $found;
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

    /**
     * Removes a location and, when it was the default, hands that flag to
     * the survivor that was already going to receive the files anyway.
     *
     * **Deleting the default used to leave the table with none**, which is
     * half of the state this class exists to keep unobservable (see the
     * docblock above) and the half that is easy to miss, because nothing
     * breaks: {@see findDefault()} falls back to the lowest id, so
     * consumers keep writing somewhere sensible and
     * {@see StorageLocationService::ensureDefaultExists()} sees a non-null
     * answer and repairs nothing. What an administrator saw was a
     * configuration page where NO location is marked « Défaut » while new
     * albums quietly landed on one of them.
     *
     * The successor is the lowest id — **the same row `findDefault()` was
     * already resolving to**. So this promotion decides nothing on the
     * administrator's behalf that was not already decided; it only makes
     * the recorded flag agree with where the bytes actually go. That is
     * also why the deletion is not simply refused: refusing would forbid
     * an operation that is safe, to protect an invariant that costs one
     * UPDATE to keep.
     *
     * Deleting the last location leaves the table empty, and that is a
     * legitimate state: `ensureDefaultExists()` recreates the default on
     * the next request, exactly as on a fresh install.
     */
    public function delete(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $wasDefault = $this->isDefaultWithin($id);

            $stmt = $this->pdo->prepare('DELETE FROM storage_locations WHERE id = ?');
            $stmt->execute([$id]);

            if ($wasDefault) {
                // The derived table is not decoration: this UPDATE reads
                // the table it writes, which MySQL 8 refuses (error 1093)
                // unless the subquery is wrapped. MariaDB accepts both
                // spellings, so the stricter one is the one written.
                $this->pdo->prepare(
                    'UPDATE storage_locations SET is_default = 1
                     WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM storage_locations) AS successor)'
                )->execute();
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Whether this row carries the default flag — read inside the open
     * transaction, and **locked**.
     *
     * A plain `SELECT` would not do, for the reason {@see create()}'s own
     * docblock gives: under REPEATABLE READ it reads a snapshot and takes
     * no lock, so a concurrent transaction could delete or re-flag this
     * row between the read and the promotion that depends on it. Two
     * concurrent deletions could then agree that neither needs to promote
     * anybody, and leave the table with no default at all — the very state
     * this class exists to keep unobservable.
     *
     * {@see setDefault()}'s own read needs no such clause: it runs after
     * that method's blanket `UPDATE … SET is_default = 0`, which has
     * already taken an exclusive lock on every row of the table in the
     * same transaction. This one has no preceding write to inherit from.
     */
    private function isDefaultWithin(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT is_default FROM storage_locations WHERE id = ?' . $this->forUpdate()
        );
        $stmt->execute([$id]);
        $flag = $stmt->fetchColumn();

        return $flag !== false && (int) $flag === 1;
    }

    /**
     * ` FOR UPDATE`, or nothing on SQLite.
     *
     * SQLite has no such clause and needs none — it serialises writers
     * itself. Every other engine this runs on needs it wherever a read
     * decides what a later write in the same transaction will do.
     */
    private function forUpdate(): string
    {
        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    public function recordCheckResult(int $id, bool $ok, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE storage_locations SET last_checked_at = ?, last_check_ok = ?, last_check_error = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $ok ? 1 : 0, $error, $id]);
    }

    /**
     * Refuses rather than substituting `{}`.
     *
     * An empty record does not read as « this failed to encode »; it
     * reads as a location configured with nothing, which resolves to the
     * default folder for a local one and loses the endpoint and the
     * bucket for an object store. Silently writing it turns an encoding
     * failure into a location pointing somewhere else entirely.
     *
     * @throws \JsonException
     */
    private static function encodeConfig(LocationConfig $config): string
    {
        return json_encode(
            $config->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
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
