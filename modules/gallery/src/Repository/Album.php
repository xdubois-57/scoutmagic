<?php

declare(strict_types=1);

namespace Modules\Gallery\Repository;

final class Album
{
    public const TYPE_LOCAL = 'local';
    public const TYPE_EXTERNAL = 'external';

    /** @var string[] */
    public const TYPES = [self::TYPE_LOCAL, self::TYPE_EXTERNAL];

    public const MIGRATION_NONE = 'none';
    public const MIGRATION_IN_PROGRESS = 'in_progress';
    public const MIGRATION_FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $albumDate,
        public readonly ?int $sectionId,
        public readonly int $scoutYearId,
        public readonly ?int $coverMediaId,
        public readonly ?string $externalUrl,
        public readonly ?string $ogTitle,
        public readonly ?string $ogDescription,
        public readonly ?string $ogImageUrl,
        public readonly ?int $ogImageFileId,
        public readonly ?int $storageLocationId,
        public readonly string $migrationStatus,
        public readonly ?int $migrationTargetLocationId,
        public readonly ?string $migrationError,
        public readonly int $createdBy,
        public readonly string $createdAt
    ) {
    }

    public function isLocal(): bool
    {
        return $this->type === self::TYPE_LOCAL;
    }

    /**
     * Media reads/listings must be gated while a migration is in flight (a
     * partially copied media set could render inconsistently) — a 'failed'
     * migration does NOT gate availability, since the source is always left
     * fully intact until the very last step of a successful run.
     */
    public function isMigrating(): bool
    {
        return $this->migrationStatus === self::MIGRATION_IN_PROGRESS;
    }
}
