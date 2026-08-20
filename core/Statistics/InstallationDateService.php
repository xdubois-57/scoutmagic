<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Config\SettingService;

/**
 * Owns the `installed_at` setting: when this ScoutMagic installation came
 * into existence, in ISO 8601 UTC.
 *
 * A brand-new install gets it written at the end of first-time setup
 * (Core\Http\Controller\SetupController). Every installation that predates
 * this feature gets it backfilled once at boot, from the oldest event
 * journal entry — the closest thing to a birth certificate the database
 * has — falling back to "now" when the journal is empty (a site so fresh
 * that nothing has been logged yet, where "now" is the honest answer
 * anyway).
 *
 * Strictly idempotent: it only ever writes while the setting is still
 * empty, so a second boot, a second setup attempt, or the two paths racing
 * each other can never move the recorded date.
 */
class InstallationDateService
{
    public const SETTING_KEY = 'installed_at';

    public function __construct(
        private SettingService $settingService,
        private \PDO $pdo
    ) {
    }

    /**
     * Declare the setting row.
     *
     * Deliberately here rather than inline in public/index.php alongside
     * the other core settings: Core\Http\Controller\SetupController has to
     * write this value at the end of first-time setup, before the composition
     * root has ever run, and duplicating the French label/description in two
     * places would guarantee they drift.
     */
    public static function register(SettingService $settingService): void
    {
        $settingService->register(self::SETTING_KEY, '', 'text', 'Date d\'installation de ScoutMagic',
            'Date de première installation de ce site, au format ISO 8601 UTC. Renseignée automatiquement.',
            null, null, null, false, 284);
    }

    /**
     * Fill `installed_at` if it is still empty. Returns the value now in
     * force, or null when the setting row does not exist yet.
     */
    public function ensureRecorded(): ?string
    {
        $current = $this->settingService->get(self::SETTING_KEY);
        if (is_string($current) && trim($current) !== '') {
            return trim($current);
        }

        $resolved = $this->oldestJournalEntry() ?? self::nowIso();
        $this->settingService->claimIfEmpty(self::SETTING_KEY, $resolved);

        $persisted = $this->settingService->get(self::SETTING_KEY);

        return is_string($persisted) && trim($persisted) !== '' ? trim($persisted) : null;
    }

    /**
     * The current instant in ISO 8601 UTC — the format every consumer of
     * this setting (payload, support package, receiver) expects.
     */
    public static function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }

    /**
     * The oldest `event_log.logged_at`, normalized to ISO 8601 UTC, or null
     * when the journal is empty or unreadable. `logged_at` is a naive
     * timestamp written in the server's own timezone, so it is interpreted
     * that way before being converted.
     */
    private function oldestJournalEntry(): ?string
    {
        try {
            $stmt = $this->pdo->query('SELECT MIN(logged_at) FROM event_log');
            $value = $stmt !== false ? $stmt->fetchColumn() : false;
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
