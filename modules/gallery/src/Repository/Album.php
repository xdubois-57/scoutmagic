<?php

declare(strict_types=1);

namespace Modules\Gallery\Repository;

final class Album
{
    public const TYPE_LOCAL = 'local';
    public const TYPE_EXTERNAL = 'external';

    /** @var string[] */
    public const TYPES = [self::TYPE_LOCAL, self::TYPE_EXTERNAL];

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
        public readonly int $createdBy,
        public readonly string $createdAt
    ) {
    }

    public function isLocal(): bool
    {
        return $this->type === self::TYPE_LOCAL;
    }
}
