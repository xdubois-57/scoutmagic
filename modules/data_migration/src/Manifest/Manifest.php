<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Manifest;

/**
 * A checked `manifest.json` (docs/formats/data-migration/conventions.md).
 */
final class Manifest
{
    public const FILE = 'manifest.json';

    /**
     * @param array<string, array{sha256: string, size: ?int}> $files path => inventory line
     */
    public function __construct(
        public readonly string $format,
        public readonly string $formatVersion,
        public readonly int $majorVersion,
        public readonly string $installationId,
        public readonly string $exportId,
        public readonly string $exportedAt,
        public readonly array $files
    ) {
    }
}
