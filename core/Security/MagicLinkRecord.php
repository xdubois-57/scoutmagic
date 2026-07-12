<?php

declare(strict_types=1);

namespace Core\Security;

class MagicLinkRecord
{
    public function __construct(
        public readonly int $id,
        public readonly string $emailBlindIndex,
        public readonly string $tokenHash,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly bool $used,
        public readonly ?\DateTimeImmutable $confirmedAt,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }
}
