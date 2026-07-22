<?php

declare(strict_types=1);

namespace Modules\MassMail\Repository;

final class Recipient
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';

    public function __construct(
        public readonly int $id,
        public readonly int $emailId,
        public readonly int $memberId,
        public readonly int $scoutYearId,
        public readonly ?string $emailAddress,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly ?string $sentAt,
        public readonly int $attempts,
        public readonly string $createdAt
    ) {
    }
}
