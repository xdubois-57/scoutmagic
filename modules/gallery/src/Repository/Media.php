<?php

declare(strict_types=1);

namespace Modules\Gallery\Repository;

final class Media
{
    public const TYPE_PHOTO = 'photo';
    public const TYPE_VIDEO = 'video';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly int $albumId,
        public readonly string $mediaType,
        public readonly int $fileId,
        public readonly ?string $thumbPath,
        public readonly ?string $mediumPath,
        public readonly ?string $largePath,
        public readonly ?string $originalPath,
        public readonly string $processingStatus,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $durationSeconds,
        public readonly int $sortOrder,
        public readonly ?string $originalFilename,
        public readonly string $createdAt
    ) {
    }

    public function isVideo(): bool
    {
        return $this->mediaType === self::TYPE_VIDEO;
    }
}
