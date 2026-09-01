<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingService;

/**
 * "Is a real cron actually running on this installation?", answered from
 * the three traces that exist, and from nowhere else.
 *
 * A real crontab firing every minute is the engine the scheduler is built
 * around, and since the in-request triggers were removed it is the only
 * one there is. The reference installation nonetheless ran for days with a
 * crontab entry that executed nothing at all — the hosting panel had been
 * given a bare script path where it needed `php /htdocs/public/cron.php` —
 * and no page anywhere said so. This class exists so that both the setup
 * page and the maintenance page can say it.
 *
 * The three sources, and why each one is needed:
 *
 * - **The heartbeat file** (`storage/temp/cron-heartbeat`), written at the
 *   very top of `public/cron.php` before the autoloader. It is the only
 *   source that works **before the site is initialized**, which is exactly
 *   when the setup gate has to reach a verdict, and the only one that
 *   distinguishes "the crontab fired but the pass died" from "the crontab
 *   never fired".
 * - **`cron_last_run`**, the setting the same script stamps once it has a
 *   database. That is the last FULL successful pass, and it survives a
 *   `storage/temp` sweep, which the heartbeat does not.
 * - **`Core\Scheduler\CronRunHistory`**, the ring buffer beside it, which
 *   is the only source of an interval — one stamp cannot tell an hourly
 *   crontab apart from a per-minute one.
 *
 * Purely read-only: nothing here writes, registers a setting, or touches
 * the scheduler. It is safe to build on a request that has no database.
 */
final class CronHealth
{
    /**
     * A real cron is the intended engine and it is expected every minute,
     * so "active" means "has been heard from within the last few minutes",
     * not "at some point today". Three minutes leaves room for one missed
     * tick plus clock skew without ever calling a working crontab dead.
     */
    public const ACTIVE_WITHIN_SECONDS = 180;

    /**
     * Beyond this, a cron that once ran is reported as silent rather than
     * merely late. `Core\Support\Collector\CronCadenceCollector` and
     * `Core\Statistics\StatisticsPayloadBuilder` both decide the same
     * thing on the same number, and the three must not disagree.
     */
    public const STALE_AFTER_SECONDS = 7200;

    /** Written by public/cron.php, relative to the storage path. */
    public const HEARTBEAT_FILE = 'temp/cron-heartbeat';

    /**
     * @param string $storagePath Absolute path to `storage/`. An empty string means "no heartbeat readable" — never an error.
     * @param SettingService|null $settings Null before the site is initialized: only the heartbeat is then consulted.
     */
    public function __construct(
        private readonly string $storagePath,
        private readonly ?SettingService $settings = null
    ) {
    }

    /**
     * The crontab line to configure, in ONE place.
     *
     * The `php ` prefix is not decoration. Hosting panels that accept a
     * bare script path in an "Adresse du script" field hand it to the
     * shell, which executes nothing at all and reports nothing either —
     * the failure mode this whole class exists to surface.
     *
     * @param string $publicDir Absolute path of the directory holding cron.php (`__DIR__` in public/index.php).
     */
    public static function crontabLine(string $publicDir): string
    {
        $script = $publicDir !== ''
            ? rtrim($publicDir, '/') . '/cron.php'
            : '/chemin/vers/le/site/public/cron.php';

        return '* * * * * php ' . $script;
    }

    public function status(?int $now = null): CronStatus
    {
        $now ??= time();

        $heartbeat = $this->readHeartbeat();
        $fullPass = $this->readLastFullPass();
        $intervals = $this->medianInterval();

        $lastSeen = max($heartbeat ?? 0, $fullPass ?? 0);

        if ($lastSeen <= 0) {
            $state = CronStatus::STATE_NEVER;
        } elseif (($now - $lastSeen) <= self::ACTIVE_WITHIN_SECONDS) {
            $state = CronStatus::STATE_ACTIVE;
        } else {
            $state = CronStatus::STATE_STALE;
        }

        return new CronStatus($state, $heartbeat, $fullPass, $intervals, $now);
    }

    /**
     * The file's own content is the timestamp; its mtime is the fallback
     * for a truncated or half-written file (a cron killed mid-write, a
     * full disk). A file that exists at all is evidence the crontab fired,
     * so an unreadable content is never allowed to erase that.
     */
    private function readHeartbeat(): ?int
    {
        if ($this->storagePath === '') {
            return null;
        }

        $path = rtrim($this->storagePath, '/') . '/' . self::HEARTBEAT_FILE;
        if (!@is_file($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));
        if ($raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }

        $mtime = @filemtime($path);

        return $mtime === false ? null : $mtime;
    }

    private function readLastFullPass(): ?int
    {
        if ($this->settings === null) {
            return null;
        }

        $stamp = (int) ($this->settings->get('cron_last_run') ?? 0);

        return $stamp > 0 ? $stamp : null;
    }

    /**
     * Median gap between the recorded passes, or null with fewer than two
     * of them — one timestamp is not an interval, and answering "0" or
     * "unknown-as-a-number" there is how a cadence display starts lying.
     */
    private function medianInterval(): ?int
    {
        if ($this->settings === null) {
            return null;
        }

        $history = CronRunHistory::read($this->settings);
        if (count($history) < 2) {
            return null;
        }

        $gaps = [];
        for ($i = 1, $n = count($history); $i < $n; $i++) {
            $gaps[] = $history[$i] - $history[$i - 1];
        }

        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }
}
