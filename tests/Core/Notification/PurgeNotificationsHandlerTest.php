<?php

declare(strict_types=1);

namespace Tests\Core\Notification;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationRepository;
use Core\Notification\Task\PurgeNotificationsHandler;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Mes notifications ont disparu » had no answer at all — not even « la
 * conservation les a prises, elle est réglée à 90 jours ».
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeNotificationsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    public function testAPurgeThatDeletedSomethingSaysHowMuchAndWhy(): void
    {
        $this->readNotificationOlderThanTheRetention();

        (new PurgeNotificationsHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertSame('notifications_purged', $entry['event_type']);
        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(1, $context['deleted']);
        $this->assertSame(90, $context['retention_days']);
    }

    public function testTheJournalNamesNoRecipientAndNoTitle(): void
    {
        // A count and a retention, and nothing of what the notification
        // said or who it was for (§7.9).
        $this->readNotificationOlderThanTheRetention('Votre enfant a été inscrit');

        (new PurgeNotificationsHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertStringNotContainsString('enfant', $entry['description'] . (string) $entry['context']);
    }

    public function testANightWithNothingToPurgeWritesNothing(): void
    {
        (new PurgeNotificationsHandler())->handle([], $this->taskContext());

        $this->assertSame([], (new JournalRepository($this->pdo))->search());
    }

    public function testAnUnreadNotificationIsNeverPurgedAndNothingIsSaid(): void
    {
        $this->notification('Non lue', null);

        (new PurgeNotificationsHandler())->handle([], $this->taskContext());

        $this->assertSame([], (new JournalRepository($this->pdo))->search());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()
        );
    }

    private function readNotificationOlderThanTheRetention(string $title = 'Titre'): void
    {
        $this->notification($title, (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s'));
    }

    private function notification(string $title, ?string $readAt): void
    {
        (new NotificationRepository($this->pdo, $this->encryption))->create(1, null, 'test.type', $title, 'Corps', '/');
        if ($readAt !== null) {
            $this->pdo->exec("UPDATE notifications SET read_at = '{$readAt}'");
        }
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }
}
