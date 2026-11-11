<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Repository;

/**
 * One row of `data_migration_runs`, decoded.
 */
final class Run
{
    public const UPLOADED = 'uploaded';
    public const ANALYSED = 'analysed';
    public const CONFIRMED = 'confirmed';
    public const RUNNING = 'running';
    public const INTERRUPTED = 'interrupted';
    public const DONE = 'done';
    public const ABANDONED = 'abandoned';

    public const STATES = [
        self::UPLOADED, self::ANALYSED, self::CONFIRMED, self::RUNNING,
        self::INTERRUPTED, self::DONE, self::ABANDONED,
    ];

    /**
     * @param array<string, mixed> $sourceParameters
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $analysis
     * @param list<string> $chosenYears
     * @param array<string, mixed> $report
     */
    public function __construct(
        public readonly int $id,
        public readonly string $importerKey,
        public readonly string $state,
        public readonly ?string $filePath,
        public readonly ?string $fileName,
        public readonly ?int $fileSize,
        public readonly array $sourceParameters,
        public readonly ?string $format,
        public readonly ?string $formatVersion,
        public readonly ?string $installationId,
        public readonly ?string $exportId,
        public readonly ?string $exportedAt,
        public readonly array $progress,
        public readonly array $analysis,
        public readonly array $chosenYears,
        public readonly array $report,
        public readonly ?string $rejectionReason,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $confirmedAt,
        public readonly ?string $finishedAt
    ) {
    }

    /** Neither finished nor abandoned: the run still owns its file and can move. */
    public function isActive(): bool
    {
        return !in_array($this->state, [self::DONE, self::ABANDONED], true);
    }

    public function phase(): string
    {
        $phase = $this->progress['phase'] ?? '';

        return is_string($phase) ? $phase : '';
    }

    public function position(): int
    {
        return (int) ($this->progress['position'] ?? 0);
    }
}
