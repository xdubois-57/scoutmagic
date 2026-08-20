<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Task\AutoCloseHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AutoCloseHandlerTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    private function createOpenBoard(bool $closeNotifyEnabled = true, ?string $closeNotifyEmail = null): int
    {
        return $this->boardRepository->create(
            'Board', '2026-07-01', null, bin2hex(random_bytes(8)), null, true, 'unlimited', 5,
            true, 'cookie', 140, '24h', (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'), null,
            $closeNotifyEnabled, $closeNotifyEmail
        );
    }

    public function testClosesAnOpenBoard(): void
    {
        $boardId = $this->createOpenBoard();

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);

        $this->assertSame('closed', $this->boardRepository->findById($boardId)->status);
    }

    public function testIsIdempotentWhenTheBoardWasAlreadyClosedManually(): void
    {
        $boardId = $this->createOpenBoard();
        $this->boardRepository->close($boardId);

        // Must not throw or double-log — the chief may have closed it
        // manually before this scheduled task ran.
        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_auto_closed'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testLogsTheAutoCloseToJournal(): void
    {
        $boardId = $this->createOpenBoard();

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_auto_closed'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testIgnoresAnUnknownBoardId(): void
    {
        // Must not throw.
        (new AutoCloseHandler())->handle(['board_id' => 999999], $this->context);
        $this->assertTrue(true);
    }

    public function testIgnoresAMissingBoardIdInThePayload(): void
    {
        (new AutoCloseHandler())->handle([], $this->context);
        $this->assertTrue(true);
    }

    public function testSendsTheNotificationEmailWhenEnabledAndAddressPresent(): void
    {
        $boardId = $this->createOpenBoard(true, 'chief@example.com');
        $this->context->mailService->expects($this->once())->method('send')->with('chief@example.com');

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);
    }

    public function testDoesNotSendWhenNotifyIsDisabled(): void
    {
        $boardId = $this->createOpenBoard(false, 'chief@example.com');
        $this->context->mailService->expects($this->never())->method('send');

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);
    }

    public function testDoesNotSendWhenNoAddressIsConfigured(): void
    {
        $boardId = $this->createOpenBoard(true, null);
        $this->context->mailService->expects($this->never())->method('send');

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);
    }

    public function testJournalLogsWhenTheMailServiceThrows(): void
    {
        $boardId = $this->createOpenBoard(true, 'chief@example.com');
        $this->context->mailService->method('send')->willThrowException(new \RuntimeException('SMTP down'));

        (new AutoCloseHandler())->handle(['board_id' => $boardId], $this->context);

        $entries = (new JournalRepository($this->pdo))->search('retro');
        $failure = null;
        foreach ($entries as $entry) {
            if ($entry['event_type'] === 'board_close_notification_failed') {
                $failure = $entry;
                break;
            }
        }
        $this->assertNotNull($failure, 'a failed close notification must leave a journal trace');
        $this->assertStringContainsString('SMTP down', (string) $failure['context']);
    }
}
