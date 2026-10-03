<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health;

use Core\Scheduler\CronStatus;

/**
 * What was measured on this host, and nothing else — HostHealth turns it
 * into lines. Kept apart so the lines can be tested for every state
 * without a host that lacks ffmpeg, sodium or a writable storage/.
 */
final class HostFacts
{
    /**
     * @param list<string> $missingMailExtensions the PHP extensions the IMAP library needs that are not loaded
     */
    public function __construct(
        public readonly CronStatus $cron,
        /** A shell-execution function is callable (not in disable_functions). */
        public readonly bool $shellDeclared,
        /** And running a command through it actually works (ShellExecutor::probe()). */
        public readonly bool $shellWorks,
        public readonly ?string $ffmpegPath,
        public readonly ?string $ffprobePath,
        public readonly bool $zipEncryption,
        public readonly bool $sodium,
        public readonly bool $gd,
        public readonly array $missingMailExtensions,
        public readonly string $phpVersion,
        public readonly string $databaseDriver,
        public readonly string $databaseVersion,
        public readonly bool $storageWritable,
        /** Core\Http\InsecureBrowserAccess's last observation, null when never. */
        public readonly ?int $lastInsecureAccessAt,
        /** When these facts were measured — what « il y a 3 h » counts from. */
        public readonly int $measuredAt,
    ) {
    }
}
