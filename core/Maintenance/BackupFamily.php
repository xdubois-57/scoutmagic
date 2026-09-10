<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Which kind of backup a `backups` row is, for retention purposes.
 *
 * **Deduced from the type, never stored.** A `backups.family` column would
 * be a second source of truth for something the type already decides, and
 * the two would drift the first time a type was added without it — a row
 * whose type says « avant mise à jour » and whose family says « manuelle »
 * is not a state anybody could reason about. The mapping lives here, and
 * `BackupFamilyCoverageTest` refuses a type that has no family.
 *
 * **Why families exist at all.** The cap used to be five backups, all
 * kinds mixed, oldest deleted first. Three updates in a row therefore
 * evicted the full backup an administrator had taken deliberately five
 * minutes earlier — the automatic ones outnumber the wanted ones, so a
 * single ordered list always ends up keeping the noise and dropping the
 * signal. Each family now keeps its own, and an update pushes out an older
 * update.
 *
 * The numbers are in `docs/exigences-non-fonctionnelles.md` §4bis, and the
 * three that are settings can be raised by an administrator who has the
 * disk for it.
 */
enum BackupFamily: string
{
    /** Somebody clicked a button on Configuration › Maintenance. */
    case Manual = 'manual';

    /** The scheduled full backup, taken by the cron with nobody watching. */
    case Scheduled = 'scheduled';

    /** The safety net taken automatically just before a risky operation. */
    case Operational = 'operational';

    /**
     * The archive that carries the site's own keys and is meant to leave.
     *
     * Its own family rather than a fourth kind of manual backup, because
     * everything retention does with a family it must do differently with
     * this one: exactly one is kept, the number is not an administrator's
     * to raise, and its lingering presence on the server is itself an
     * alert ({@see \Core\Alert\Check\PortableBackupLingerCheck}). Filed
     * under `Manual` it would have inherited a quota of three, which is
     * three copies of the master key sitting where the backup was supposed
     * to take it away from.
     */
    case Portable = 'portable';

    /**
     * The family a `backups.type` belongs to, or null when the type is not
     * one this version knows.
     *
     * Null rather than a guess or an exception, and that direction is
     * deliberate: the one caller is a purge. A backup it cannot classify
     * is a backup it must not count and must not delete — keeping an
     * unknown row costs disk, deleting it costs the only copy of
     * something. `BackupFamilyCoverageTest` makes sure this never actually
     * happens for a type the schema ships.
     */
    public static function tryFromType(string $type): ?self
    {
        return match ($type) {
            'database', 'full_config', 'full_no_gallery', 'full_with_gallery' => self::Manual,
            'auto_backup' => self::Scheduled,
            'auto_update', 'auto_reset' => self::Operational,
            Backup::PORTABLE_TYPE => self::Portable,
            default => null,
        };
    }

    /** Shown on the badge of every row in « Sauvegardes récentes ». */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle',
            self::Scheduled => 'Planifiée',
            self::Operational => 'Avant opération',
            self::Portable => 'Portable',
        };
    }

    /** The Bootstrap contextual class the badge carries (design.md §7). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Manual => 'text-bg-primary',
            self::Scheduled => 'text-bg-info',
            self::Operational => 'text-bg-secondary',
            // The one badge that is a warning rather than a category:
            // this row is an archive holding the site's keys, and the
            // colour is the mockup's own (amber) read into Bootstrap.
            self::Portable => 'text-bg-warning',
        };
    }

    /**
     * The `settings` key holding how many of this family to keep, or null
     * for a family whose count is not an administrator's to choose.
     *
     * Null for `Portable`, and that is the whole mechanism rather than an
     * omission: {@see \Core\Maintenance\BackupRetention::quotaFor()}
     * reads no setting at all when there is no key, so no row in
     * `settings` — hand-written, restored from an older site, or added by
     * a future version — can raise this one. A second copy of the master
     * key on the server is not a preference.
     */
    public function quotaSettingKey(): ?string
    {
        return match ($this) {
            self::Manual => 'backup_keep_manual',
            self::Scheduled => 'backup_keep_scheduled',
            self::Operational => 'backup_keep_operational',
            self::Portable => null,
        };
    }

    /**
     * How many to keep when the setting says nothing.
     *
     * Three of each rather than five of everything: three is enough to
     * step back past a bad one twice, and three families of three is nine
     * on a disk where five used to be the whole allowance — which is why
     * `Core\Storage\DiskBudget` had to land first (IT-02).
     */
    public function defaultQuota(): int
    {
        // One for the portable archive, and not because it is large: it
        // holds the keys to everything, so the second copy is a second
        // liability sitting on the very server the backup exists to
        // survive. The new one replaces the old, which is what the screen
        // promises.
        return $this === self::Portable ? 1 : 3;
    }
}
