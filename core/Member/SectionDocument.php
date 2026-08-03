<?php

declare(strict_types=1);

namespace Core\Member;

class SectionDocument
{
    public const COMPRESSION_PENDING = 'pending';
    public const COMPRESSION_COMPRESSED = 'compressed';
    public const COMPRESSION_SKIPPED = 'skipped';

    public function __construct(
        public readonly int $id,
        public readonly int $sectionId,
        public readonly int $scoutYearId,
        public readonly int $fileId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $sortOrder,
        public readonly string $compressionStatus,
        public readonly ?int $sizeBeforeBytes,
        public readonly ?int $sizeAfterBytes,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }
}
