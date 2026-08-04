<?php

declare(strict_types=1);

namespace Core\Notification;

/**
 * One browser/device subscribed to Web Push for a given account.
 * endpoint/authKey/p256dhKey are already decrypted here —
 * PushSubscriptionRepository is the only layer that touches
 * EncryptionService.
 */
class PushSubscription
{
    public function __construct(
        public readonly int $id,
        public readonly int $userAccountId,
        public readonly string $endpoint,
        public readonly string $authKey,
        public readonly string $p256dhKey,
        public readonly ?string $deviceLabel,
        public readonly string $createdAt,
        public readonly ?string $lastSuccessAt,
        public readonly int $failureCount
    ) {
    }
}
