<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;

/**
 * The last few moments `public/cron.php` actually ran, as a ring buffer.
 *
 * `cron_last_run` already records that a real crontab exists, but it is a
 * single stamp overwritten on every pass — which answers "did it ever run"
 * and cannot answer "how often". Those are different questions, and the
 * second one is the one that matters: a crontab configured hourly on a
 * host that silently drops it looks identical, through one stamp, to a
 * crontab firing every minute. Only the intervals tell them apart, and an
 * interval needs two timestamps.
 *
 * Twenty entries: enough to see a cadence and to notice a gap in it,
 * small enough that the row stays a few hundred bytes in `settings`.
 *
 * Deliberately written by `public/cron.php` alone, exactly like
 * `cron_last_run` — the poor man's cron in `public/index.php` stamps
 * `scheduler_last_run` instead, and mixing the two would erase the very
 * distinction the support package needs to report.
 */
final class CronRunHistory
{
    public const SETTING = 'cron_run_history';

    public const MAX_ENTRIES = 20;

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::SETTING,
            '[]',
            'text',
            'Historique des passages du cron réel',
            'Les ' . self::MAX_ENTRIES . ' derniers horodatages (timestamps Unix) de public/cron.php, '
            . 'en JSON. Sert à calculer la cadence réelle du cron dans le paquet de support. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
    }

    /**
     * Append $now and keep the newest MAX_ENTRIES.
     *
     * Reads through the REPOSITORY rather than the service, deliberately:
     * SettingService caches what it has read, and this method reads and
     * writes in the same call, so going through the cache would append to
     * a snapshot rather than to the stored row. It does not matter in
     * production — `public/cron.php` records once per process — and
     * depending on that would be a correctness argument resting on a
     * caller's shape.
     *
     * Best effort by construction: this runs at the very top of every cron
     * pass, and a support diagnostic must never be the reason a cron pass
     * does not happen.
     */
    public static function record(SettingRepository $repository, int $now): void
    {
        try {
            $stored = $repository->findByModuleAndKey(null, self::SETTING);
            $history = self::parse(is_array($stored) ? (string) ($stored['setting_value'] ?? '') : '');
            $history[] = $now;
            if (count($history) > self::MAX_ENTRIES) {
                $history = array_slice($history, -self::MAX_ENTRIES);
            }

            $repository->updateValue(null, self::SETTING, (string) json_encode(array_values($history)));
        } catch (\Throwable) {
            // Never fatal: see the doc comment.
        }
    }

    /**
     * Ascending, integers only. A malformed row reads as "no history"
     * rather than throwing — the same rule the schema-hash cache follows
     * for a corrupted value.
     *
     * @return array<int, int>
     */
    public static function read(SettingService $settings): array
    {
        return self::parse((string) ($settings->get(self::SETTING) ?? ''));
    }

    /**
     * @return array<int, int>
     */
    private static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $stamps = [];
        foreach ($decoded as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $stamps[] = (int) $entry;
            }
        }

        sort($stamps);

        return $stamps;
    }
}
