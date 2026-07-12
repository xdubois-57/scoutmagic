<?php

declare(strict_types=1);

namespace Core\Security;

class UserAccount
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $passwordHash,
        public readonly bool $isSuperAdmin,
        public readonly ?\DateTimeImmutable $lastLoginAt
    ) {
    }
}
