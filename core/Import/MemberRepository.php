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
