<?php

declare(strict_types=1);

namespace Tests\Core\File\Held\Task;

use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\Held\HeldDocumentRepository;
use Core\File\Held\HeldDocumentService;
use Core\File\Held\Task\PurgeHeldDocumentsHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The hourly purge of the documents the installed application put aside
 * (issue #502): expired ones go, file and row, opened or not; the others
 * stay; and the task re-arms itself.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PurgeHeldDocumentsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/held_purge_test_' . bin2hex(random_bytes(4));
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->storagePath);
        }
    }

    public function testExpiredDocumentsAreDeletedAndLiveOnesKept(): void
    {
        $this->hold(new \DateTimeImmutable('-2 hours'));
        $this->hold(new \DateTimeImmutable());

        (new PurgeHeldDocumentsHandler())->handle([], $this->context);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM held_documents')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        $this->assertCount(1, glob($this->storagePath . '/' . HeldDocumentService::DIRECTORY . '/*') ?: []);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'held_documents_purged'")->fetchColumn()
        );
    }

    public function testNothingToPurgeWritesNoJournalLine(): void
    {
        $this->hold(new \DateTimeImmutable());

        (new PurgeHeldDocumentsHandler())->handle([], $this->context);

        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'held_documents_purged'")->fetchColumn()
        );
    }

    public function testTheTaskReArmsItself(): void
    {
        (new PurgeHeldDocumentsHandler())->handle([], $this->context);

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = '" . PurgeHeldDocumentsHandler::TASK_KEY . "'"
            )->fetchColumn()
        );
    }

    private function hold(\DateTimeImmutable $at): void
    {
        $files = new FileRepository($this->pdo);
        (new HeldDocumentService(
            new HeldDocumentRepository($this->pdo),
            new EncryptedFileStorageService($files, $this->encryption, $this->storagePath),
            $files,
            new JournalService(new JournalRepository($this->pdo))
        ))->hold('%PDF', 'application/pdf', 'a.pdf', 'session', null, $at);
    }
}
