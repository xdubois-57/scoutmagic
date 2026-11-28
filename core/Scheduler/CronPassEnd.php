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
 * The moment the last `public/cron.php` pass reached its END.
 *
 * Every other trace a pass leaves is written at its start: the heartbeat
 * before the autoloader, `cron_last_run` and `CronRunHistory` once the
 * single-flight lock is held — before the modules are loaded, before the
 * schema migration, before a single task runs. A pass that dies after
 * that (memory exhausted, process killed by the host, a fatal error in a
 * handler) therefore looked exactly like one that did its work, and
 * `CronHealth` went on reporting the cron as active while nothing
 * finished (issue #1439).
 *
 * This stamp is written as the last thing a pass does, so it is the one
 * that says « a pass went all the way through ». `CronHealth` compares it
 * with the starts the ring buffer holds: passes run one at a time, so two
 * starts recorded after the last end mean at least one pass started and
 * never finished.
 *
 * Written by `public/cron.php` alone, like the stamps beside it.
 */
final class CronPassEnd
{
    public const SETTING = 'cron_last_pass_end';

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::SETTING,
            '0',
            'number',
            'Dernier passage du cron réel terminé',
            'Horodatage (timestamp Unix) de la fin du dernier passage de public/cron.php allé jusqu\'au bout — '
            . 'écrit par lui seul, après toutes les tâches. Un passage qui démarre sans arriver ici a été '
            . 'interrompu. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
    }

    /**
     * Best effort, like CronRunHistory::record(): a diagnostic stamp must
     * never be what turns a finished pass into a failed one.
     */
    public static function record(SettingRepository $repository, int $now): void
    {
        try {
            $repository->updateValue(null, self::SETTING, (string) $now);
        } catch (\Throwable) {
            // Never fatal: see the doc comment.
        }
    }

    /** Null when no pass has ever been recorded as finished. */
    public static function read(SettingService $settings): ?int
    {
        $stamp = (int) ($settings->get(self::SETTING) ?? 0);

        return $stamp > 0 ? $stamp : null;
    }
}
