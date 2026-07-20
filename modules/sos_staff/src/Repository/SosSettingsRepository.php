<?php

declare(strict_types=1);

namespace Modules\SosStaff\Repository;

/**
 * sos_settings is a single global row — application-enforced singleton,
 * same "one row, no DB-level constraint" precedent as the calendar
 * module's calendar_unit_feed_token.
 */
class SosSettingsRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The explicit admin override, if any (null means "not overridden —
     * auto-resolve the section responsable", see Service\SosSettingsService).
     */
    public function findDefaultNumberMemberId(): ?int
    {
        $stmt = $this->pdo->query('SELECT default_number_member_id FROM sos_settings ORDER BY id ASC LIMIT 1');
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        return $value !== false && $value !== null ? (int) $value : null;
    }

    public function saveDefaultNumberMember(int $memberId): void
    {
        $id = $this->ensureRow();
        $stmt = $this->pdo->prepare(
            'UPDATE sos_settings SET default_number_member_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$memberId, $id]);
    }

    /**
     * One-shot bookkeeping flag — see schema.sql's comment on
     * sos_settings.sections_defaults_seeded.
     */
    public function areSectionDefaultsSeeded(): bool
    {
        $stmt = $this->pdo->query('SELECT sections_defaults_seeded FROM sos_settings ORDER BY id ASC LIMIT 1');
        $value = $stmt !== false ? $stmt->fetchColumn() : false;
        return $value !== false && (bool) $value;
    }

    public function markSectionDefaultsSeeded(): void
    {
        $id = $this->ensureRow();
        $stmt = $this->pdo->prepare('UPDATE sos_settings SET sections_defaults_seeded = 1 WHERE id = ?');
        $stmt->execute([$id]);
    }

    private function ensureRow(): int
    {
        $stmt = $this->pdo->query('SELECT id FROM sos_settings ORDER BY id ASC LIMIT 1');
        $id = $stmt !== false ? $stmt->fetchColumn() : false;
        if ($id !== false) {
            return (int) $id;
        }

        $this->pdo->exec('INSERT INTO sos_settings (default_number_member_id) VALUES (NULL)');
        return (int) $this->pdo->lastInsertId();
    }
}
