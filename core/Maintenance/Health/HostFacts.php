<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health;

use Core\Scheduler\CronStatus;
use Core\System\CronExecutionFacts;

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
        /** THIS (web) PHP: a shell-execution function is callable (not in disable_functions). */
        public readonly bool $shellDeclared,
        /** And running a command through it actually works (ShellExecutor::probe()). */
        public readonly bool $shellWorks,
        public readonly bool $zipEncryption,
        public readonly bool $sodium,
        public readonly bool $gd,
        public readonly array $missingMailExtensions,
        public readonly string $phpVersion,
        public readonly string $databaseDriver,
        public readonly string $databaseVersion,
        public readonly bool $storageWritable,
        /** The web PHP's execution function, and the probe's exact words (#700). */
        public readonly ?string $shellFunction = null,
        public readonly string $shellDetail = '',
        /**
         * What the CRON's PHP can execute, measured by public/cron.php —
         * null while it never has. Video depends on this, not on the web.
         */
        public readonly ?CronExecutionFacts $cronExecution = null,
        /** `proc_open` may be called, by the web PHP that compresses an upload. */
        public readonly bool $procOpen = false,
        /** {@see \Core\Pdf\PdfCompressor}'s chosen tool, `none` when there is none. */
        public readonly string $pdfBackend = 'none',
        /** When these facts were measured — what « il y a » counts from. */
        public readonly int $measuredAt = 0,
    ) {
    }
}
