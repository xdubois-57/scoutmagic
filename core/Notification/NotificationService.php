<?php

declare(strict_types=1);

namespace Core\Notification;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Generic Web Push notification service (RFC 8030), usable by any
 * Core\Scheduler\TaskHandlerInterface via TaskContext::$notifications —
 * not tied to any specific background task. $webPush is injected already
 * configured with the site's VAPID keys (composition root), so this class
 * never touches secrets.enc directly.
 */
class NotificationService
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private PushSubscriptionRepository $subscriptionRepository,
        private WebPush $webPush
    ) {
    }

    /**
     * Records the notification and best-effort pushes it to every device
     * registered for this account. The notifications row is always
     * created, even with zero registered devices (module spec: visible in
     * a future in-app notification center regardless of push delivery).
     * A device whose push service reports the subscription gone (410/404)
     * is silently deregistered; any other per-device failure is ignored
     * so one bad device never blocks the rest.
     */
    public function notify(int $userAccountId, string $title, string $body, ?string $url = null): void
    {
        $this->notificationRepository->create($userAccountId, $title, $body, $url);

        $subscriptions = $this->subscriptionRepository->findByUserAccountId($userAccountId);
        if ($subscriptions === []) {
            return;
        }

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $subscription) {
            try {
                $report = $this->webPush->sendOneNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'keys' => [
                            'p256dh' => $subscription->p256dhKey,
                            'auth' => $subscription->authKey,
                        ],
                    ]),
                    $payload
                );

                if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                    $this->subscriptionRepository->delete($subscription->id);
                }
            } catch (\Throwable) {
                // Best-effort: a single device failing (network error,
                // malformed endpoint, etc.) must never block the others or
                // the notifications row already created above.
            }
        }
    }

    /**
     * Registers a device for push notifications. Idempotent: re-subscribing
     * the same endpoint (e.g. the browser rotated its keys) replaces the
     * previous row rather than duplicating it.
     */
    public function subscribe(int $userAccountId, string $endpoint, string $authKey, string $p256dhKey): void
    {
        $this->subscriptionRepository->deleteByEndpoint($userAccountId, $endpoint);
        $this->subscriptionRepository->create($userAccountId, $endpoint, $authKey, $p256dhKey);
    }

    public function unsubscribe(int $userAccountId, string $endpoint): void
    {
        $this->subscriptionRepository->deleteByEndpoint($userAccountId, $endpoint);
    }

    /**
     * Removes every device registered for this account (toggle off in
     * "Mon compte" — the account-wide preference, as opposed to
     * unsubscribe() which drops a single device).
     */
    public function unsubscribeAll(int $userAccountId): void
    {
        $this->subscriptionRepository->deleteAllForUser($userAccountId);
    }
}
