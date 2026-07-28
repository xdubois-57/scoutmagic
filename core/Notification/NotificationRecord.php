<?php

declare(strict_types=1);

namespace Core\Notification;

/**
 * A row in the `notifications` history table. Named NotificationRecord
 * rather than Notification to avoid any confusion with
 * Minishlink\WebPush\Notification, an unrelated internal class of the
 * Web Push library used by NotificationService.
 */
class NotificationRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $userAccountId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url,
        public readonly bool $isRead,
        public readonly string $createdAt
    ) {
    }
}
