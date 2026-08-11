<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Repository;

final class StorageLocation
{
    public const TYPE_LOCAL = 'local';
    public const TYPE_S3 = 's3';

    /** @var string[] */
    public const TYPES = [self::TYPE_LOCAL, self::TYPE_S3];

    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $label,
        public readonly bool $isDefault,
        public readonly ?string $subdir,
        public readonly ?string $s3Provider,
        public readonly ?string $s3Endpoint,
        public readonly ?string $s3Region,
        public readonly ?string $s3Bucket,
        public readonly ?string $s3AccessKey,
        public readonly ?string $s3PublicUrl,
        public readonly bool $secretConfigured,
        public readonly ?string $lastCheckedAt,
        public readonly ?bool $lastCheckOk,
        public readonly ?string $lastCheckError,
        public readonly string $createdAt
    ) {
    }

    public function isS3(): bool
    {
        return $this->type === self::TYPE_S3;
    }
}
