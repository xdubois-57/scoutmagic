<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert;

/**
 * The numbers `docs/exigences-non-fonctionnelles.md` §4 fixes, in code.
 *
 * **Two values per check, never one.** A check with a single threshold
 * notifies on every scheduler pass as soon as the value oscillates around
 * it — disk at 84.9 %, then 85.1 %, then 84.9 % again — and an alert that
 * cries three times a day is switched off within the week. The re-arm
 * value is deliberately and strictly lower than the trigger, so a value
 * has to genuinely recover before the check will speak again.
 *
 * These constants are the single place the two documents meet. When one of
 * them moves, it moves here and on that page in the same change; a
 * threshold that exists in only one of the two is how a requirement stops
 * being a requirement.
 */
final class AlertThresholds
{
    /** Disk occupation, as a percentage of the quota or volume in use. */
    public const DISK_TRIGGER_PERCENT = 85;
    public const DISK_REARM_PERCENT = 75;

    /** Age of the last successful backup, in days. */
    public const BACKUP_AGE_TRIGGER_DAYS = 10;
    public const BACKUP_AGE_REARM_DAYS = 3;

    /**
     * Age of the last REAL cron pass, in hours — `cron_last_run`, which
     * only `public/cron.php` stamps. Never `scheduler_last_run`, which the
     * poor man's cron writes on every visit and which therefore says
     * nothing about whether a crontab exists (ARCHITECTURE.md §8.24).
     */
    public const CRON_SILENT_TRIGGER_HOURS = 48;
    public const CRON_SILENT_REARM_HOURS = 6;

    /**
     * Stored backups the last verification pass could not read.
     *
     * One and zero, and the absence of a gap is the honest shape here
     * rather than an exception to the rule above. The others watch a level
     * that drifts and needs room to come back; a backup that cannot be
     * read is not a level but a copy of the unit's work that no longer
     * exists, and there is no count of them small enough to tolerate. The
     * re-arm at zero is the meaningful half: nothing repairs a truncated
     * archive, so the alert goes quiet when the last unreadable one has
     * been deleted (`Core\Alert\Check\BackupIntegrityCheck`).
     */
    public const BACKUP_UNREADABLE_TRIGGER_COUNT = 1;
    public const BACKUP_UNREADABLE_REARM_COUNT = 0;

    /**
     * Hours of uninterrupted encrypted traffic before the HTTPS alert goes
     * quiet again.
     *
     * The one check whose trigger side carries no number, which is the
     * limit case of the rule above rather than an exception to it. The
     * others watch a level that drifts — a percentage, an age — and have
     * to decide how far it must come back. This one watches an event: a
     * request answered in clear, seen as it happens. There is no level to
     * cross, so the trigger is the event itself and the only tunable
     * number is how long the site must stay quiet before the alert
     * believes it (`Core\Alert\Check\HttpsCheck`).
     *
     * A day, because that is long enough to outlast a site answering on
     * BOTH schemes — the failure this number exists for, and one that
     * alternates on the timescale of visitors arriving rather than of days
     * — and short enough that an administrator who repairs a certificate
     * this morning sees the alert clear by tomorrow, having edited
     * nothing.
     */
    public const HTTPS_REARM_QUIET_HOURS = 24;

    /**
     * Failed e-mail sends counted over this window, and the count that
     * trips the alert.
     *
     * One failure is a bounced relay or a full mailbox and is not news.
     * Five in a day is a configuration that has stopped working, which is
     * the state this check exists to catch — and which is otherwise
     * invisible, because every caller of `MailService::send()` treats a
     * failure as non-fatal.
     */
    public const MAIL_FAILURE_WINDOW_HOURS = 24;
    public const MAIL_FAILURE_TRIGGER_COUNT = 5;
    public const MAIL_FAILURE_REARM_COUNT = 0;
}
