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
        public readonly bool $shellAvailable,
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
    ) {
    }
}
