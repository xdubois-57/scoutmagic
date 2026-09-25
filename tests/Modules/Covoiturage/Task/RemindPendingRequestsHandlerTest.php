<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Scheduler\TaskContext;
use Core\Security\UserAccountRepository;
use Modules\Covoiturage\Task\RemindPendingRequestsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;

/**
 * covoiturage.request_pending: to the driver, for a request that has
 * waited, never for a trip gone, and not again before a few days.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RemindPendingRequestsHandlerTest extends TestCase
{
    private \PDO $pdo;
    /** @var list<array{type: string, to: list<int>, body: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
    }

    private function context(): TaskContext
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('dispatch')->willReturnCallback(function (string $type, array $recipients, array $payload): void {
            $this->sent[] = [
                'type' => $type,
                'to' => array_map(static fn(array $r): int => (int) $r['userAccountId'], $recipients),
                'body' => (string) $payload['body'],
            ];
        });

        return new TaskContext(
            Connection::withPdo($this->pdo),
            H::encryption(),
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, H::encryption()),
            sys_get_temp_dir(),
            $notifications
        );
    }

    private function ageRequest(int $id, int $days): void
    {
        $at = (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE carpool_requests SET created_at = ? WHERE id = ?')->execute([$at, $id]);
    }

    public function testTheDriverIsRemindedOfARequestThatWaitedAndOnlyOnce(): void
    {
        $offer = H::offer($this->pdo, H::carpool($this->pdo, 5), 1);
        $old = H::request($this->pdo, $offer, 2, ['Tom']);
        $this->ageRequest($old, 3);
        H::request($this->pdo, $offer, 3, ['Kim']); // asked today: not yet

        (new RemindPendingRequestsHandler())->handle([], $this->context());

        $this->assertCount(1, $this->sent);
        $this->assertSame('covoiturage.request_pending', $this->sent[0]['type']);
        $this->assertSame([1], $this->sent[0]['to']);
        $this->assertSame('Famille Leroy, depuis 3 jours. Départ dans 5 jours.', $this->sent[0]['body']);

        $this->sent = [];
        (new RemindPendingRequestsHandler())->handle([], $this->context());
        $this->assertSame([], $this->sent, 'Reminded again the next day.');
    }

    public function testNoReminderForATripAlreadyGone(): void
    {
        $offer = H::offer($this->pdo, H::carpool($this->pdo, -1), 1);
        $this->ageRequest(H::request($this->pdo, $offer, 2, ['Tom']), 4);

        (new RemindPendingRequestsHandler())->handle([], $this->context());

        $this->assertSame([], $this->sent);
    }
}
