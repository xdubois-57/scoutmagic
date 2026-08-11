<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

class FileRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $relativePath,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $roleMin,
        public readonly ?string $customResolver,
        public readonly bool $encrypted,
        public readonly ?int $ownerMemberId = null,
        public readonly ?string $ownerType = null,
        public readonly ?int $ownerId = null
    ) {
    }
}
