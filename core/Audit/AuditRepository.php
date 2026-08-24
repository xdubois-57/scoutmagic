<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

use Core\Security\EncryptionService;

/**
 * The only layer that ever sees an entity_changes row's ciphertext
 * (SECURITY.md §5): everything above receives AuditEntry objects whose
 * three values are plain strings, and hands back plain strings to record.
 *
 * The actor's name is decrypted here too, from the joined user_accounts
 * row, so the timeline never has to reach for a second service to put a
 * name on a line.
 */
class AuditRepository
{
    /** What an anonymised value is replaced with, in every column at once. */
    public const ANONYMISED_MARKER = '(anonymisé)';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    public function insert(
        string $entityType,
        int $entityId,
        string $fieldKey,
        ?string $from,
        ?string $to,
        AuditSource $source,
        ?string $summary,
        ?string $sourceReference,
        ?int $actorUserAccountId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO entity_changes
                (entity_type, entity_id, field_key, from_value, to_value, summary, source,
                 source_reference, actor_user_account_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $entityType,
            $entityId,
            $fieldKey,
            $this->encryptNullable($from),
            $this->encryptNullable($to),
            $this->encryptNullable($summary),
            $source->value,
            $sourceReference,
            $actorUserAccountId,
            date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countForEntity(string $entityType, int $entityId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entity_changes WHERE entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$entityType, $entityId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Newest first, and tie-broken by id: two changes saved by the same
     * form land on the same DATETIME second, and an order that depends on
     * whatever the storage engine returns would shuffle them between two
     * page loads of the same history.
     *
     * @return AuditEntry[]
     */
    public function findForEntity(string $entityType, int $entityId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, a.first_name_encrypted, a.last_name_encrypted
               FROM entity_changes c
               LEFT JOIN user_accounts a ON a.id = c.actor_user_account_id
              WHERE c.entity_type = ? AND c.entity_id = ?
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute([$entityType, $entityId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Replaces the three encrypted values with a fixed marker on every row
     * matching an (entity, field) pair, and returns how many rows changed.
     *
     * The marker is re-encrypted rather than written in clear, so every row
     * in the column stays readable through exactly one code path — a
     * mixture of ciphertext and plaintext in one BLOB column is how a
     * decrypt-everything reader starts throwing on real data.
     *
     * @param int[]    $entityIds
     * @param string[] $fieldKeys
     */
    public function anonymiseValues(string $entityType, array $entityIds, array $fieldKeys): int
    {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        $fieldKeys = array_values(array_unique($fieldKeys));
        if ($entityIds === [] || $fieldKeys === []) {
            return 0;
        }

        $idPlaceholders = implode(',', array_fill(0, count($entityIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($fieldKeys), '?'));
        $marker = $this->encryption->encrypt(self::ANONYMISED_MARKER, 'entity_changes.value');

        $stmt = $this->pdo->prepare(
            "UPDATE entity_changes
                SET from_value = CASE WHEN from_value IS NULL THEN NULL ELSE ? END,
                    to_value   = CASE WHEN to_value   IS NULL THEN NULL ELSE ? END,
                    summary    = CASE WHEN summary    IS NULL THEN NULL ELSE ? END
              WHERE entity_type = ?
                AND entity_id IN ({$idPlaceholders})
                AND field_key IN ({$keyPlaceholders})"
        );
        $stmt->execute(array_merge([$marker, $marker, $marker, $entityType], $entityIds, $fieldKeys));

        return $stmt->rowCount();
    }

    /**
     * The same replacement, but only on the rows whose own values name one
     * of $needles.
     *
     * `anonymiseValues()` is the blunt instrument: it clears every row of a
     * field, which is right when a person asks to be erased and every row
     * of that field on those entities is about them. It is wrong when one
     * person among several leaves — a camp's contact history holds three
     * different people's details under the same `contact` field, and
     * clearing all of them to erase one would destroy two histories nobody
     * asked to lose.
     *
     * Matching happens in PHP because the values are encrypted with a
     * random IV: no `LIKE` can reach inside them, and a blind index on a
     * free-text history line would be a searchable index of exactly the
     * personal data this column exists to protect. Comparison is
     * accent-insensitive only in case, deliberately: a needle is a value
     * this application itself wrote, not user-typed search input.
     *
     * @param int[]    $entityIds
     * @param string[] $fieldKeys
     * @param string[] $needles
     */
    public function anonymiseValuesMatching(
        string $entityType,
        array $entityIds,
        array $fieldKeys,
        array $needles
    ): int {
        $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
        $fieldKeys = array_values(array_unique($fieldKeys));
        $needles = array_values(array_filter(
            array_map(static fn(string $n): string => trim($n), $needles),
            static fn(string $n): bool => $n !== ''
        ));
        if ($entityIds === [] || $fieldKeys === [] || $needles === []) {
            return 0;
        }

        $idPlaceholders = implode(',', array_fill(0, count($entityIds), '?'));
        $keyPlaceholders = implode(',', array_fill(0, count($fieldKeys), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT id, from_value, to_value, summary
               FROM entity_changes
              WHERE entity_type = ?
                AND entity_id IN ({$idPlaceholders})
                AND field_key IN ({$keyPlaceholders})"
        );
        $stmt->execute(array_merge([$entityType], $entityIds, $fieldKeys));

        $marker = $this->encryption->encrypt(self::ANONYMISED_MARKER, 'entity_changes.value');
        $update = $this->pdo->prepare(
            'UPDATE entity_changes
                SET from_value = CASE WHEN from_value IS NULL THEN NULL ELSE ? END,
                    to_value   = CASE WHEN to_value   IS NULL THEN NULL ELSE ? END,
                    summary    = CASE WHEN summary    IS NULL THEN NULL ELSE ? END
              WHERE id = ?'
        );

        $changed = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $haystack = implode("\n", array_filter([
                $this->decryptNullable($row['from_value'], 'entity_changes.value'),
                $this->decryptNullable($row['to_value'], 'entity_changes.value'),
                $this->decryptNullable($row['summary'], 'entity_changes.value'),
            ], static fn(?string $v): bool => $v !== null));
            if ($haystack === '') {
                continue;
            }

            foreach ($needles as $needle) {
                if (mb_stripos($haystack, $needle) !== false) {
                    $update->execute([$marker, $marker, $marker, (int) $row['id']]);
                    $changed++;
                    break;
                }
            }
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditEntry
    {
        $first = $this->decryptNullable($row['first_name_encrypted'] ?? null, 'user_accounts.first_name');
        $last = $this->decryptNullable($row['last_name_encrypted'] ?? null, 'user_accounts.last_name');
        $name = trim(($first ?? '') . ' ' . ($last ?? ''));

        return new AuditEntry(
            id: (int) $row['id'],
            fieldKey: (string) $row['field_key'],
            fromValue: $this->decryptNullable($row['from_value'], 'entity_changes.value'),
            toValue: $this->decryptNullable($row['to_value'], 'entity_changes.value'),
            summary: $this->decryptNullable($row['summary'], 'entity_changes.value'),
            source: AuditSource::from((string) $row['source']),
            sourceReference: $row['source_reference'] !== null ? (string) $row['source_reference'] : null,
            actorUserAccountId: $row['actor_user_account_id'] !== null ? (int) $row['actor_user_account_id'] : null,
            actorName: $name !== '' ? $name : null,
            createdAt: (string) $row['created_at'],
        );
    }

    private function encryptNullable(?string $value): ?string
    {
        return $value !== null ? $this->encryption->encrypt($value, 'entity_changes.value') : null;
    }

    private function decryptNullable(mixed $value, string $context): ?string
    {
        return $value !== null && $value !== '' ? $this->encryption->decrypt((string) $value, $context) : null;
    }
}
