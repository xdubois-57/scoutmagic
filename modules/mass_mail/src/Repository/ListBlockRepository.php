<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * mass_mail_list_blocks and a member group's three criteria junctions
 * (mass_mail_block_functions/_sections/_badges) — what a custom list is
 * made of.
 *
 * The criteria are read with their block, since they are a 1:1 extension
 * of it; resolving them to members is Repository\
 * MemberResolutionRepository's job, and a contact group's addresses are
 * Repository\ContactRepository's.
 *
 * Nothing here changes a block's `kind`: there is no statement that could.
 */
class ListBlockRepository
{
    /**
     * Each statement written out in full rather than built from a table
     * name: nothing is ever interpolated into SQL here
     * (Tests\Security\SqlInjectionAuditTest), not even a constant.
     */
    private const CRITERIA_OF_BLOCK = [
        'function_id' => 'SELECT block_id, function_id FROM mass_mail_block_functions WHERE block_id = ?',
        'section_id' => 'SELECT block_id, section_id FROM mass_mail_block_sections WHERE block_id = ?',
        'badge_id' => 'SELECT block_id, badge_id FROM mass_mail_block_badges WHERE block_id = ?',
    ];

    private const CRITERIA_OF_LIST = [
        'function_id' => 'SELECT j.block_id, j.function_id FROM mass_mail_block_functions j
            JOIN mass_mail_list_blocks b ON b.id = j.block_id WHERE b.list_id = ?',
        'section_id' => 'SELECT j.block_id, j.section_id FROM mass_mail_block_sections j
            JOIN mass_mail_list_blocks b ON b.id = j.block_id WHERE b.list_id = ?',
        'badge_id' => 'SELECT j.block_id, j.badge_id FROM mass_mail_block_badges j
            JOIN mass_mail_list_blocks b ON b.id = j.block_id WHERE b.list_id = ?',
    ];

    private const DELETE_CRITERIA = [
        'function_id' => 'DELETE FROM mass_mail_block_functions WHERE block_id = ?',
        'section_id' => 'DELETE FROM mass_mail_block_sections WHERE block_id = ?',
        'badge_id' => 'DELETE FROM mass_mail_block_badges WHERE block_id = ?',
    ];

    private const INSERT_CRITERION = [
        'function_id' => 'INSERT INTO mass_mail_block_functions (block_id, function_id) VALUES (?, ?)',
        'section_id' => 'INSERT INTO mass_mail_block_sections (block_id, section_id) VALUES (?, ?)',
        'badge_id' => 'INSERT INTO mass_mail_block_badges (block_id, badge_id) VALUES (?, ?)',
    ];

    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $listId, string $kind, ?string $name, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_list_blocks (list_id, kind, name, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$listId, $kind, $name, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?ListBlock
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_list_blocks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row, [
            'function_id' => $this->idsFor(self::CRITERIA_OF_BLOCK['function_id'], [$id])[$id] ?? [],
            'section_id' => $this->idsFor(self::CRITERIA_OF_BLOCK['section_id'], [$id])[$id] ?? [],
            'badge_id' => $this->idsFor(self::CRITERIA_OF_BLOCK['badge_id'], [$id])[$id] ?? [],
        ]);
    }

    /**
     * A list's blocks in the one order the screen shows them: member
     * groups first, then contact groups, each by creation. Ordered in SQL
     * on a CASE rather than on `kind` itself, because MySQL sorts an ENUM
     * by its declaration index and SQLite sorts the text — the two would
     * disagree.
     *
     * Four queries whatever the number of blocks: the criteria of all of
     * them are read at once, joined through the list.
     *
     * @return ListBlock[]
     */
    public function findForList(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM mass_mail_list_blocks WHERE list_id = ?
             ORDER BY CASE kind WHEN 'member_group' THEN 0 ELSE 1 END, created_at, id"
        );
        $stmt->execute([$listId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        $byColumn = array_map(fn(string $sql): array => $this->idsFor($sql, [$listId]), self::CRITERIA_OF_LIST);

        return array_map(function (array $row) use ($byColumn): ListBlock {
            $id = (int) $row['id'];

            return $this->hydrate($row, [
                'function_id' => $byColumn['function_id'][$id] ?? [],
                'section_id' => $byColumn['section_id'][$id] ?? [],
                'badge_id' => $byColumn['badge_id'][$id] ?? [],
            ]);
        }, $rows);
    }

    /**
     * How many blocks of each kind every custom list holds — the page of
     * lists prints « 2 groupes de membres · 1 groupe de contacts » per
     * card, and this answers it for the whole page in one query.
     *
     * A list without any block is absent from the result.
     *
     * @return array<int, array{member_group: int, contact_group: int}> list id => counts
     */
    public function countKindsByList(): array
    {
        $stmt = $this->pdo->query(
            'SELECT list_id, kind, COUNT(*) AS n FROM mass_mail_list_blocks GROUP BY list_id, kind'
        );
        $counts = [];
        foreach ($stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $listId = (int) $row['list_id'];
            $counts[$listId] ??= [ListBlock::KIND_MEMBER_GROUP => 0, ListBlock::KIND_CONTACT_GROUP => 0];
            $counts[$listId][(string) $row['kind']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Deletes the block; its criteria and its contacts go with it by
     * cascade, and the junction rows are deleted explicitly as well so the
     * SQLite test database, which does not enforce the cascade, ends in
     * the same state.
     */
    public function delete(int $id): void
    {
        foreach (self::DELETE_CRITERIA as $sql) {
            $this->pdo->prepare($sql)->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM mass_mail_block_contacts WHERE block_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_list_blocks WHERE id = ?')->execute([$id]);
    }

    /**
     * A member group's criteria become exactly these: deleted and
     * reinserted, which is why the pickers must offer every id a group
     * still names (Service\MailingListService::getAllBadges()).
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     */
    public function replaceCriteria(int $blockId, array $functionIds, array $sectionIds, array $badgeIds): void
    {
        $values = ['function_id' => $functionIds, 'section_id' => $sectionIds, 'badge_id' => $badgeIds];

        foreach (self::DELETE_CRITERIA as $column => $sql) {
            $this->pdo->prepare($sql)->execute([$blockId]);

            $insert = $this->pdo->prepare(self::INSERT_CRITERION[$column]);
            foreach (array_unique($values[$column]) as $id) {
                $insert->execute([$blockId, $id]);
            }
        }
    }

    public function rename(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE mass_mail_list_blocks SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function markFileReplaced(int $id, \DateTimeImmutable $at): void
    {
        $this->pdo->prepare('UPDATE mass_mail_list_blocks SET file_replaced_at = ? WHERE id = ?')
            ->execute([$at->format('Y-m-d H:i:s'), $id]);
    }

    /**
     * Every section id any member group still names as a criterion, and
     * every badge id — across ALL lists.
     *
     * Service\MailingListService::getAllSections()/getAllBadges() need
     * them: a picker offering only what is currently active would have no
     * item for a section or a badge that was deactivated after being made
     * a criterion, and an id with no item in the DOM is an id the form
     * silently drops on the next save — see those two methods.
     *
     * @return int[]
     */
    public function findReferencedSectionIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT section_id FROM mass_mail_block_sections');

        return $stmt !== false ? array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    /**
     * @return int[]
     */
    public function findReferencedBadgeIds(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT badge_id FROM mass_mail_block_badges');

        return $stmt !== false ? array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) : [];
    }

    /**
     * Runs $work in one transaction, or inside the caller's when one is
     * already open — the shape of EmailRepository::atomically(). Creating
     * a contact group is a block AND its contacts: a failure between the
     * two must not leave an empty group behind.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function atomically(callable $work): mixed
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
     * @param array<int, int> $parameters
     * @return array<int, int[]> block id => ids
     */
    private function idsFor(string $sql, array $parameters): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$blockId, $id]) {
            $ids[(int) $blockId][] = (int) $id;
        }
        return array_map(static function (array $list): array {
            sort($list);

            return $list;
        }, $ids);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{function_id: int[], section_id: int[], badge_id: int[]} $criteria
     */
    private function hydrate(array $row, array $criteria): ListBlock
    {
        return new ListBlock(
            id: (int) $row['id'],
            listId: (int) $row['list_id'],
            kind: (string) $row['kind'],
            name: $row['name'] !== null ? (string) $row['name'] : null,
            fileReplacedAt: $row['file_replaced_at'] !== null ? (string) $row['file_replaced_at'] : null,
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null,
            functionIds: $criteria['function_id'],
            sectionIds: $criteria['section_id'],
            badgeIds: $criteria['badge_id']
        );
    }
}
