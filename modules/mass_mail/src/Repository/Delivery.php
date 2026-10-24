<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One message of a mailing — always to exactly ONE address
 * (mass_mail_deliveries, issue #999). What is sent, tracked, resent and
 * unsubscribed from; which members it concerns is in
 * mass_mail_delivery_targets, never in this row.
 */
final class Delivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_ERROR = 'error';

    public function __construct(
        public readonly int $id,
        public readonly int $emailId,
        // The file row this message was frozen from (mail merge only),
        // null otherwise and after the audience purge.
        public readonly ?int $audienceRowId,
        public readonly string $emailAddress,
        // The Core\Member\MemberEmail row this address maps to — what the
        // one-click unsubscribe deactivates. Null for a contact or a file
        // address.
        public readonly ?int $memberEmailId,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly ?string $sentAt,
        public readonly int $attempts,
        public readonly string $createdAt
    ) {
    }

    public function isFinished(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }
}
