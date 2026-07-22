<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

final class MailingList
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $isActive,
        public readonly string $createdAt,
        public readonly ?int $createdBy
    ) {
    }
}
