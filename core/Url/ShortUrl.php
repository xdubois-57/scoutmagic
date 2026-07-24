<?php

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
