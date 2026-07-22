<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * Custom mailing lists (mass_mail_lists) — identity/lifecycle only. The
 * selection criteria (mass_mail_list_functions/mass_mail_list_sections)
 * are managed here too, since they're a 1:1 extension of a list's own
 * row, but resolving a list's actual member set is Repository\
 * MemberResolutionRepository's job, not this one's.
 */
class MailingListRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return MailingList[]
     */
    public function findAllOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM mass_mail_lists ORDER BY name ASC');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function findById(int $id): ?MailingList
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_lists WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     */
    public function create(string $name, string $description, array $functionIds, array $sectionIds, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$name, $description, $createdBy]);
        $id = (int) $this->pdo->lastInsertId();

        $this->replaceCriteria($id, $functionIds, $sectionIds);

        return $id;
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     */
    public function update(int $id, string $name, string $description, array $functionIds, array $sectionIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE mass_mail_lists SET name = ?, description = ? WHERE id = ?');
        $stmt->execute([$name, $description, $id]);

        $this->replaceCriteria($id, $functionIds, $sectionIds);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE mass_mail_lists SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_list_functions WHERE list_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_list_sections WHERE list_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM mass_mail_lists WHERE id = ?')->execute([$id]);
    }

    /**
     * @return int[]
     */
    public function getFunctionIds(int $listId): array
    {
        $stmt = $this->pdo->prepare('SELECT function_id FROM mass_mail_list_functions WHERE list_id = ?');
        $stmt->execute([$listId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return int[]
     */
    public function getSectionIds(int $listId): array
    {
        $stmt = $this->pdo->prepare('SELECT section_id FROM mass_mail_list_sections WHERE list_id = ?');
        $stmt->execute([$listId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Whether at least one email (any status) was created against this
     * list — Service\MailingListService::delete()'s "deactivate instead"
     * guard, same Core\Badge precedent as BadgeRepository::
     * badgeHasAnyAssignment().
     */
    public function isReferencedByAnyEmail(int $listId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM mass_mail_emails WHERE list_id = ? LIMIT 1');
        $stmt->execute([$listId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param int[] $functionIds
     * @param int[] $sectionIds
     */
    private function replaceCriteria(int $listId, array $functionIds, array $sectionIds): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_list_functions WHERE list_id = ?')->execute([$listId]);
        $this->pdo->prepare('DELETE FROM mass_mail_list_sections WHERE list_id = ?')->execute([$listId]);

        $functionStmt = $this->pdo->prepare('INSERT INTO mass_mail_list_functions (list_id, function_id) VALUES (?, ?)');
        foreach (array_unique($functionIds) as $functionId) {
            $functionStmt->execute([$listId, $functionId]);
        }

        $sectionStmt = $this->pdo->prepare('INSERT INTO mass_mail_list_sections (list_id, section_id) VALUES (?, ?)');
        foreach (array_unique($sectionIds) as $sectionId) {
            $sectionStmt->execute([$listId, $sectionId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MailingList
    {
        return new MailingList(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) $row['created_at'],
            createdBy: $row['created_by'] !== null ? (int) $row['created_by'] : null
        );
    }
}
