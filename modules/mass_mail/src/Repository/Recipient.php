<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
        // Which Core\Member\MemberEmail row this address maps to (module
        // addendum, multi-email support) — null only for the "no usable
        // address at all" error row. Task\SendBatchHandler's one-click
        // unsubscribe link is built from this, never a bare member/email
        // id in the URL.
        public readonly ?int $memberEmailId,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly ?string $sentAt,
        public readonly int $attempts,
        public readonly string $createdAt
    ) {
    }
}
