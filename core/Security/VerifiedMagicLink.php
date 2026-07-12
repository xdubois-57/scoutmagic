<?php

declare(strict_types=1);

namespace Core\Security;

class VerifiedMagicLink
{
    public function __construct(
        public readonly string $email,
        public readonly int $userAccountId
    ) {
    }
}
