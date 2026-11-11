<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;

/**
 * The ZIP an archive-based import accepts: which `format` its manifest
 * names, which major version of it this site reads, and the limits.
 */
final class ArchiveFormat
{
    /** The ceiling the module's upload refuses to go past, whatever a format asks. */
    public const MAX_ARCHIVE_BYTES = 2 * 1024 * 1024 * 1024;

    public function __construct(
        public readonly string $format,
        public readonly int $majorVersion,
        public readonly ArchiveLimits $limits,
        public readonly int $maxArchiveBytes = self::MAX_ARCHIVE_BYTES
    ) {
        if ($format === '' || strlen($format) > 100) {
            throw new \InvalidArgumentException('A format id is 1 to 100 bytes.');
        }
        if ($maxArchiveBytes < 1 || $maxArchiveBytes > self::MAX_ARCHIVE_BYTES) {
            throw new \InvalidArgumentException('An archive ceiling is positive and at most 2 GiB.');
        }
    }
}
