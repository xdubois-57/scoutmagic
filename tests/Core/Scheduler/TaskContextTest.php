<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Notification\NotificationService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * TaskContext is the whole world a task handler gets (module handlers are
 * auto-resolved with `new $handlerClass()` and receive nothing else), so
 * what it exposes — and what stays optional — is a contract worth pinning
 * before the scheduler bootstrap is reworked (chantier « dépendances
 * entre modules », IT-03).
 */
class TaskContextTest extends TestCase
{
    private function buildContext(?NotificationService $notifications = null, bool $withNotificationsArg = true): TaskContext
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $mailService = $this->createMock(MailService::class);
        $journal = new JournalService(new JournalRepository($pdo));
        $settings = new SettingService(new SettingRepository($pdo));
        $userAccounts = new UserAccountRepository($pdo, $encryption);

        if (!$withNotificationsArg) {
            return new TaskContext($connection, $encryption, $mailService, $journal, $settings, $userAccounts, '/tmp/storage-root');
        }

        return new TaskContext($connection, $encryption, $mailService, $journal, $settings, $userAccounts, '/tmp/storage-root', $notifications);
    }

    public function testExposesEveryDependencyItWasConstructedWith(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $connection = Connection::withPdo($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $mailService = $this->createMock(MailService::class);
        $journal = new JournalService(new JournalRepository($pdo));
        $settings = new SettingService(new SettingRepository($pdo));
        $userAccounts = new UserAccountRepository($pdo, $encryption);
        $notifications = $this->createMock(NotificationService::class);

        $context = new TaskContext($connection, $encryption, $mailService, $journal, $settings, $userAccounts, '/srv/storage', $notifications);

        $this->assertSame($connection, $context->connection);
        $this->assertSame($encryption, $context->encryption);
        $this->assertSame($mailService, $context->mailService);
        $this->assertSame($journal, $context->journal);
        $this->assertSame($settings, $context->settings);
        $this->assertSame($userAccounts, $context->userAccounts);
        $this->assertSame('/srv/storage', $context->storagePath);
        $this->assertSame($notifications, $context->notifications);
    }

    public function testNotificationsDefaultsToNullSoEveryHandlerMustNullGuardIt(): void
    {
        // Both entry points legitimately construct a context without a
        // NotificationService (VAPID keys absent or invalid — see
        // public/cron.php), so the property being nullable-by-default is
        // load-bearing, not an accident of signature.
        $context = $this->buildContext(withNotificationsArg: false);

        $this->assertNull($context->notifications);
    }

    public function testEveryExposedDependencyIsReadonly(): void
    {
        // A handler mutating the shared context would poison every later
        // task in the same scheduler pass — the promotion to readonly
        // properties is the guarantee, and this keeps it.
        $reflection = new \ReflectionClass(TaskContext::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "TaskContext::\${$property->getName()} must be readonly"
            );
        }
    }
}
