<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

class MemberRepository
{
    /**
     * `members.id` values this instance created, in the order it created
     * them.
     *
     * The duplicate detector (§8.80) works on exactly these: a person
     * re-created in Desk under a new code produces a new `members` row,
     * and that row is the only thing worth comparing against earlier
     * years. Reset at the start of each import by
     * {@see DeskImportService::import()}.
     *
     * @var int[]
     */
    private array $createdMemberIds = [];

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Find a member by the Desk identifier a CSV carries.
     *
     * **Aliases are consulted too**, and that is the whole point of them:
     * when two records of the same person have been merged
     * (`Core\Member\Duplicate\MemberMergeService`), the abandoned Desk
     * id stays in the federation's exports for ever. Without this second
     * lookup, the very next import carrying it would create a brand-new
     * `members` row and re-open the split the merge had just repaired —
     * silently, and for the same reason as the first time.
     *
     * The direct match comes first and **skips a row that has been merged
     * away**. That row keeps its own `desk_id` — a merge deletes nothing —
     * so without the exclusion it would keep answering for the code its
     * alias was created to redirect, and the alias would never be read at
     * all. Excluding it is what makes the two lookups mutually exclusive.
     *
     * @return array{id: int, desk_id: string}|null
     */
    public function findByDeskId(string $deskId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, desk_id FROM members WHERE desk_id = ? AND merged_into_member_id IS NULL'
        );
        $stmt->execute([$deskId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return [
                'id' => (int) $row['id'],
                'desk_id' => (string) $row['desk_id'],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.desk_id
             FROM member_desk_id_aliases a
             JOIN members m ON m.id = a.member_id
             WHERE a.desk_id = ?'
        );
        $stmt->execute([$deskId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'desk_id' => (string) $row['desk_id'],
        ];
    }

    /**
     * The grouped form of findByDeskId(), and the same rule: a direct
     * match on a member that has NOT been merged away first, then
     * `member_desk_id_aliases`. Two queries whatever the number of codes,
     * for a page or an export that holds many of them.
     *
     * Keyed by the code exactly as it was passed in; a code that
     * designates nobody is simply absent. The comparison is the
     * collation's (utf8mb4_unicode_ci: case-insensitive), so a row is
     * mapped back to the code asked for through a lowercased key — keyed
     * by the stored spelling, a code the engine found would still be
     * reported unknown. `MemberRepositoryOnMysqlTest` holds it.
     *
     * @param string[] $deskIds
     * @return array<string, array{id: int, desk_id: string}>
     */
    public function findByDeskIds(array $deskIds): array
    {
        $deskIds = array_values(array_unique(array_filter($deskIds, static fn(string $deskId): bool => $deskId !== '')));
        if ($deskIds === []) {
            return [];
        }

        $byKey = [];
        $placeholders = implode(',', array_fill(0, count($deskIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, desk_id FROM members WHERE desk_id IN ({$placeholders}) AND merged_into_member_id IS NULL"
        );
        $stmt->execute($deskIds);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byKey[mb_strtolower((string) $row['desk_id'])] = [
                'id' => (int) $row['id'],
                'desk_id' => (string) $row['desk_id'],
            ];
        }

        $missing = array_values(array_filter(
            $deskIds,
            static fn(string $deskId): bool => !isset($byKey[mb_strtolower($deskId)])
        ));
        if ($missing !== []) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT a.desk_id AS alias, m.id, m.desk_id
                 FROM member_desk_id_aliases a
                 JOIN members m ON m.id = a.member_id
                 WHERE a.desk_id IN ({$placeholders})"
            );
            $stmt->execute($missing);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $byKey[mb_strtolower((string) $row['alias'])] = [
                    'id' => (int) $row['id'],
                    'desk_id' => (string) $row['desk_id'],
                ];
            }
        }

        $found = [];
        foreach ($deskIds as $deskId) {
            $match = $byKey[mb_strtolower($deskId)] ?? null;
            if ($match !== null) {
                $found[$deskId] = $match;
            }
        }

        return $found;
    }

    /**
     * Find or create a member by desk_id. Returns the member ID.
     */
    public function upsertByDeskId(string $deskId): int
    {
        $existing = $this->findByDeskId($deskId);
        if ($existing !== null) {
            return $existing['id'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id, created_at) VALUES (?, ?)');
        $stmt->execute([$deskId, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->createdMemberIds[] = $id;

        return $id;
    }

    /** @return int[] */
    public function getCreatedMemberIds(): array
    {
        return $this->createdMemberIds;
    }

    public function resetCreatedMemberIds(): void
    {
        $this->createdMemberIds = [];
    }
}
