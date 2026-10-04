<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\SettingService;
use Core\System\CronExecutionFacts;

/**
 * Whether video can be transcoded here — **the cron's answer** (#700).
 *
 * The transcoding runs in a task (Task\ProcessVideoHandler), so what
 * matters is what the cron's PHP can execute, which public/cron.php
 * measures and stores (Core\System\CronExecutionFacts). This used to run
 * `which ffmpeg` from the WEB request: on a shared host whose web PHP has
 * no shell, video was refused even with ffmpeg installed, because the
 * process asked was not the one that would transcode. Both consumers —
 * the gallery and, through the delegated albums, the groups — read this
 * one answer.
 *
 * Never measured yet is **unknown**: video stays refused (nothing has
 * shown it would work), and the screens say « pas encore vérifié »
 * rather than « absent ».
 */
class FfmpegAvailability
{
    public const AVAILABLE = 'available';
    public const MISSING = 'missing';
    public const UNKNOWN = 'unknown';

    public function __construct(private SettingService $settings)
    {
    }

    public function check(): bool
    {
        return $this->state() === self::AVAILABLE;
    }

    /** One of {@see AVAILABLE}, {@see MISSING}, {@see UNKNOWN}. */
    public function state(): string
    {
        $facts = CronExecutionFacts::read($this->settings);
        if ($facts === null) {
            return self::UNKNOWN;
        }

        return $facts->videoReady() ? self::AVAILABLE : self::MISSING;
    }
}
