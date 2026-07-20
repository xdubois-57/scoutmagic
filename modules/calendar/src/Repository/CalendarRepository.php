<?php

declare(strict_types=1);

namespace Modules\Calendar\Repository;

class CalendarRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?Calendar
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_calendars WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findBySectionId(int $sectionId): ?Calendar
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_calendars WHERE section_id = ?');
        $stmt->execute([$sectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByIcsToken(string $token): ?Calendar
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_calendars WHERE ics_token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /** @return Calendar[] */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM calendar_calendars ORDER BY id');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return Calendar[] calendars with section_id set, i.e. automatic section calendars */
    public function findSectionCalendars(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM calendar_calendars WHERE section_id IS NOT NULL ORDER BY id');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return Calendar[] calendars with no section_id, i.e. supplementary calendars */
    public function findSupplementaryCalendars(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM calendar_calendars WHERE section_id IS NULL ORDER BY is_default DESC, id');
        if ($stmt === false) {
            return [];
        }
        return array_map(fn(array $row) => $this->hydrate($row), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findDefaultCalendar(): ?Calendar
    {
        $stmt = $this->pdo->query('SELECT * FROM calendar_calendars WHERE is_default = 1 LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function createSectionCalendar(int $sectionId, string $visibility): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_calendars (section_id, visibility) VALUES (?, ?)'
        );
        $stmt->execute([$sectionId, $visibility]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createSupplementaryCalendar(string $name, bool $isDefault, string $visibility, string $icsToken): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_calendars (name, is_default, visibility, ics_token) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $isDefault ? 1 : 0, $visibility, $icsToken]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateVisibility(int $id, string $visibility): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_calendars SET visibility = ? WHERE id = ?');
        $stmt->execute([$visibility, $id]);
    }

    public function updateIcsToken(int $id, string $token): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_calendars SET ics_token = ? WHERE id = ?');
        $stmt->execute([$token, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_calendars WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Calendar
    {
        return new Calendar(
            id: (int) $row['id'],
            sectionId: $row['section_id'] !== null ? (int) $row['section_id'] : null,
            name: $row['name'] !== null ? (string) $row['name'] : null,
            color: $row['color'] !== null ? (string) $row['color'] : null,
            isDefault: (bool) $row['is_default'],
            visibility: (string) $row['visibility'],
            icsToken: $row['ics_token'] !== null ? (string) $row['ics_token'] : null
        );
    }
}
