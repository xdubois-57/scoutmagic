<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Attachment
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(
        public readonly int $id,
        public readonly ?int $accountId,
        public readonly int $fileId,
        public readonly string $mimeType,
        public readonly string $originalFilename,
        public readonly ?float $suggestedAmount,
        public readonly ?string $suggestedDate,
        public readonly string $status,
        public readonly ?int $parentAttachmentId,
        public readonly ?int $uploadedBy,
        public readonly string $uploadedAt
    ) {
    }
}
