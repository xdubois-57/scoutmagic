<?php

declare(strict_types=1);

namespace Core\Security;

class MagicLinkResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $magicLinkId,
        public readonly ?string $error
    ) {
    }
}
