<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Repository;

use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;

class CalendarRepository
{
    private const TOKEN_ENCRYPTION_CONTEXT = 'calendar_calendars.ics_token';
    private const TOKEN_BLIND_INDEX_PURPOSE = 'calendar_ics_token';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
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
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_calendars WHERE ics_token_blind_index = ?');
        $stmt->execute([$this->encryption->blindIndex($token, self::TOKEN_BLIND_INDEX_PURPOSE)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // Verify exact match (blind index collisions are theoretically possible).
        $calendar = $this->hydrate($row);

        return CapabilityToken::equalsConstantTime($calendar->icsToken, $token) ? $calendar : null;
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
            'INSERT INTO calendar_calendars (name, is_default, visibility, ics_token_encrypted, ics_token_blind_index) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $name, $isDefault ? 1 : 0, $visibility,
            $this->encryption->encrypt($icsToken, self::TOKEN_ENCRYPTION_CONTEXT),
            $this->encryption->blindIndex($icsToken, self::TOKEN_BLIND_INDEX_PURPOSE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateVisibility(int $id, string $visibility): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_calendars SET visibility = ? WHERE id = ?');
        $stmt->execute([$visibility, $id]);
    }

    public function updateEditRoleMin(int $id, string $editRoleMin): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_calendars SET edit_role_min = ? WHERE id = ?');
        $stmt->execute([$editRoleMin, $id]);
    }

    public function updateIcsToken(int $id, string $token): void
    {
        $stmt = $this->pdo->prepare('UPDATE calendar_calendars SET ics_token_encrypted = ?, ics_token_blind_index = ? WHERE id = ?');
        $stmt->execute([
            $this->encryption->encrypt($token, self::TOKEN_ENCRYPTION_CONTEXT),
            $this->encryption->blindIndex($token, self::TOKEN_BLIND_INDEX_PURPOSE),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_calendars WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function decryptToken(array $row): ?string
    {
        if ($row['ics_token_encrypted'] === null) {
            return null;
        }

        return $this->encryption->decrypt((string) $row['ics_token_encrypted'], self::TOKEN_ENCRYPTION_CONTEXT);
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
            editRoleMin: (string) ($row['edit_role_min'] ?? Calendar::EDIT_ROLE_CHIEF),
            icsToken: $this->decryptToken($row)
        );
    }
}
