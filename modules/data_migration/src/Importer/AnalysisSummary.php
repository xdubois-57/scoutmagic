<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * What the analysis counted, handed to {@see Importer::previewNotes()}.
 */
final class AnalysisSummary
{
    /**
     * @param array<string, int> $ignoredReasons reason => count
     * @param array<string, int> $tags tag => count
     * @param array<string, int> $missingYears past year label => items
     */
    public function __construct(
        public readonly int $total,
        public readonly int $toImport,
        public readonly int $present,
        public readonly int $ambiguous,
        public readonly array $ignoredReasons,
        public readonly array $tags,
        public readonly array $missingYears
    ) {
    }

    public function tag(string $tag): int
    {
        return $this->tags[$tag] ?? 0;
    }

    public function ignored(): int
    {
        return array_sum($this->ignoredReasons);
    }
}
