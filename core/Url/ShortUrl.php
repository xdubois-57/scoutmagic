<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Url;

final class ShortUrl
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $targetUrl,
        public readonly string $createdAt,
        public readonly ?int $createdBy
    ) {
    }
}
