<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Security\EncryptionService;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class NotificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private NotificationRepository $notificationRepository;
    private PushSubscriptionRepository $subscriptionRepository;
    private WebPush $webPush;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->notificationRepository = new NotificationRepository($this->pdo);
        $this->subscriptionRepository = new PushSubscriptionRepository($this->pdo, $encryption);
        $this->webPush = $this->createMock(WebPush::class);
        $this->service = new NotificationService($this->notificationRepository, $this->subscriptionRepository, $this->webPush);
    }

    private function createUserAccount(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        return (int) $this->pdo->lastInsertId();
    }

    public function testNotifyCreatesANotificationRecordEvenWithNoSubscriptions(): void
    {
        $userId = $this->createUserAccount();
        $this->webPush->expects($this->never())->method('sendOneNotification');

        $this->service->notify($userId, 'Titre', 'Corps', '/some/path');

        $records = $this->notificationRepository->findByUserAccountId($userId);
        $this->assertCount(1, $records);
        $this->assertSame('Titre', $records[0]->title);
        $this->assertSame('Corps', $records[0]->body);
        $this->assertSame('/some/path', $records[0]->url);
        $this->assertFalse($records[0]->isRead);
    }

    public function testNotifySendsToEveryRegisteredDevice(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/device-1', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/device-2', 'auth2', 'p256dh2');

        $successReport = $this->createMock(MessageSentReport::class);
        $successReport->method('isSuccess')->willReturn(true);
        $successReport->method('isSubscriptionExpired')->willReturn(false);

        $this->webPush->expects($this->exactly(2))->method('sendOneNotification')->willReturn($successReport);

        $this->service->notify($userId, 'Titre', 'Corps');

        $this->assertCount(2, $this->subscriptionRepository->findByUserAccountId($userId));
    }

    public function testNotifyRemovesExpiredSubscriptionOn410(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/gone', 'auth', 'p256dh');

        $expiredReport = $this->createMock(MessageSentReport::class);
        $expiredReport->method('isSuccess')->willReturn(false);
        $expiredReport->method('isSubscriptionExpired')->willReturn(true);

        $this->webPush->method('sendOneNotification')->willReturn($expiredReport);

        $this->service->notify($userId, 'Titre', 'Corps');

        $this->assertSame([], $this->subscriptionRepository->findByUserAccountId($userId));
    }

    public function testNotifyKeepsSubscriptionOnNonExpiredFailure(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/temp-fail', 'auth', 'p256dh');

        $failedReport = $this->createMock(MessageSentReport::class);
        $failedReport->method('isSuccess')->willReturn(false);
        $failedReport->method('isSubscriptionExpired')->willReturn(false);

        $this->webPush->method('sendOneNotification')->willReturn($failedReport);

        $this->service->notify($userId, 'Titre', 'Corps');

        $this->assertCount(1, $this->subscriptionRepository->findByUserAccountId($userId));
    }

    public function testNotifyOneDeviceThrowingDoesNotBlockOthersOrTheRecord(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/broken', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/ok', 'auth2', 'p256dh2');

        $successReport = $this->createMock(MessageSentReport::class);
        $successReport->method('isSuccess')->willReturn(true);
        $successReport->method('isSubscriptionExpired')->willReturn(false);

        $callCount = 0;
        $this->webPush->method('sendOneNotification')->willReturnCallback(function () use (&$callCount, $successReport) {
            $callCount++;
            if ($callCount === 1) {
                throw new \ErrorException('Network error');
            }
            return $successReport;
        });

        $this->service->notify($userId, 'Titre', 'Corps');

        $this->assertCount(1, $this->notificationRepository->findByUserAccountId($userId));
        $this->assertSame(2, $callCount);
    }

    public function testSubscribeIsIdempotentForTheSameEndpoint(): void
    {
        $userId = $this->createUserAccount();

        $this->service->subscribe($userId, 'https://push.example/device', 'auth-old', 'p256dh-old');
        $this->service->subscribe($userId, 'https://push.example/device', 'auth-new', 'p256dh-new');

        $subscriptions = $this->subscriptionRepository->findByUserAccountId($userId);
        $this->assertCount(1, $subscriptions);
        $this->assertSame('auth-new', $subscriptions[0]->authKey);
    }

    public function testUnsubscribeRemovesOnlyThatEndpoint(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/keep', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/drop', 'auth2', 'p256dh2');

        $this->service->unsubscribe($userId, 'https://push.example/drop');

        $remaining = $this->subscriptionRepository->findByUserAccountId($userId);
        $this->assertCount(1, $remaining);
        $this->assertSame('https://push.example/keep', $remaining[0]->endpoint);
    }

    public function testUnsubscribeAllRemovesEveryDevice(): void
    {
        $userId = $this->createUserAccount();
        $this->service->subscribe($userId, 'https://push.example/a', 'auth1', 'p256dh1');
        $this->service->subscribe($userId, 'https://push.example/b', 'auth2', 'p256dh2');

        $this->service->unsubscribeAll($userId);

        $this->assertSame([], $this->subscriptionRepository->findByUserAccountId($userId));
    }
}
