<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

/**
 * A row in the `notifications` history table. Named NotificationRecord
 * rather than Notification to avoid any confusion with
 * Minishlink\WebPush\Notification, an unrelated internal class of the
 * Web Push library used by NotificationService. title/body are already
 * decrypted here — NotificationRepository is the only layer that touches
 * EncryptionService.
 */
class NotificationRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $userAccountId,
        public readonly ?int $memberId,
        public readonly string $typeId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly ?string $readAt,
        public readonly string $createdAt,
        /**
         * When the email copy left, or null — see the `notifications`
         * table comment in schema/core.sql. Null covers both "the email
         * channel was off for this recipient" and "not sent yet"; nothing
         * needs to tell those apart.
         */
        public readonly ?string $emailSentAt = null
    ) {
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function emailAlreadySent(): bool
    {
        return $this->emailSentAt !== null;
    }
}
