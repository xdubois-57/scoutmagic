<?php

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class Attachment
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const SUGGESTED_SOURCE_MANUAL = 'manual';
    public const SUGGESTED_SOURCE_AI = 'ai';

    public function __construct(
        public readonly int $id,
        public readonly ?int $accountId,
        public readonly int $fileId,
        public readonly string $mimeType,
        public readonly string $originalFilename,
        public readonly ?float $suggestedAmount,
        public readonly ?string $suggestedDate,
        public readonly ?string $suggestedLabel,
        public readonly ?string $suggestedSource,
        public readonly string $status,
        public readonly ?int $parentAttachmentId,
        public readonly ?int $uploadedBy,
        public readonly string $uploadedAt
    ) {
    }
}
