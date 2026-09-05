<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

class BadgeRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return Badge[] ordered by name */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM badges ORDER BY name');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?Badge
    {
        $stmt = $this->pdo->prepare('SELECT * FROM badges WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByName(string $name): ?Badge
    {
        $stmt = $this->pdo->prepare('SELECT * FROM badges WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Every referent badge, keyed by its section — one query for the sync
     * that runs on every Staffs page load, instead of one per section.
     *
     * @return array<int, Badge>
     */
    public function findAllByReferentSection(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM badges WHERE referent_section_id IS NOT NULL');
        $byId = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $badge = $this->hydrate($row);
            $byId[(int) $badge->referentSectionId] ??= $badge;
        }

        return $byId;
    }

    public function findByReferentSectionId(int $sectionId): ?Badge
    {
        $stmt = $this->pdo->prepare('SELECT * FROM badges WHERE referent_section_id = ?');
        $stmt->execute([$sectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $name, bool $isDefault, ?int $referentSectionId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO badges (name, is_default, is_active, referent_section_id) VALUES (?, ?, 1, ?)'
        );
        $stmt->execute([$name, $isDefault ? 1 : 0, $referentSectionId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE badges SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE badges SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM badges WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Badge
    {
        return new Badge(
            id: (int) $row['id'],
            name: (string) $row['name'],
            isDefault: (bool) $row['is_default'],
            isActive: (bool) $row['is_active'],
            referentSectionId: $row['referent_section_id'] !== null ? (int) $row['referent_section_id'] : null
        );
    }
}
