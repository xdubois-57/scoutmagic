<?php

declare(strict_types=1);

namespace Tests\Core\File\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FilePublicIds;
use Core\File\Task\BackfillFilePublicIdsHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1109: files that predate `files.public_id` get theirs from a task,
 * a slice per pass, never on a request.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class BackfillFilePublicIdsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(
            BackfillFilePublicIdsHandler::DONE_FLAG,
            '0',
            'boolean',
            'Fait',
            'Fait',
            null,
            null,
            null,
            false
        );
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $encryption,
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            sys_get_temp_dir()
        );
    }

    public function testASmallLibraryIsFilledInOnePassAndTheFlagIsSet(): void
    {
        $this->insertLegacyFiles(3);

        (new BackfillFilePublicIdsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->withoutPublicId());
        $this->assertSame('1', (string) $this->settings->get(BackfillFilePublicIdsHandler::DONE_FLAG));
        $this->assertSame(0, $this->queuedPasses());
        foreach ($this->pdo->query('SELECT public_id FROM files')->fetchAll(\PDO::FETCH_COLUMN) as $publicId) {
            $this->assertTrue(FilePublicIds::isWellFormed((string) $publicId));
        }
    }

    /**
     * A full slice re-arms the task and leaves the flag unset: the next
     * pass carries on, however large the library.
     */
    public function testAFullSliceReArmsWithoutSettingTheFlag(): void
    {
        $this->insertLegacyFiles(BackfillFilePublicIdsHandler::BATCH_SIZE + 2);

        (new BackfillFilePublicIdsHandler())->handle([], $this->context);

        $this->assertSame(2, $this->withoutPublicId());
        $this->assertNotSame('1', (string) $this->settings->get(BackfillFilePublicIdsHandler::DONE_FLAG));
        $this->assertSame(1, $this->queuedPasses());

        (new BackfillFilePublicIdsHandler())->handle([], $this->context);

        $this->assertSame(0, $this->withoutPublicId());
        $this->assertSame('1', (string) $this->settings->get(BackfillFilePublicIdsHandler::DONE_FLAG));
    }

    public function testTheTaskIsRegisteredForTheScheduler(): void
    {
        $this->assertSame(
            BackfillFilePublicIdsHandler::class,
            \Core\Scheduler\CoreTaskHandlers::all()[BackfillFilePublicIdsHandler::TASK_KEY] ?? null
        );
    }

    private function insertLegacyFiles(int $count): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, ?, ?, ?, ?)'
        );
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute(["legacy/$i.jpg", "$i.jpg", 'image/jpeg', 1, 'public']);
        }
    }

    private function withoutPublicId(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM files WHERE public_id IS NULL')->fetchColumn();
    }

    private function queuedPasses(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE task_key = ? AND status = ?');
        $stmt->execute([BackfillFilePublicIdsHandler::TASK_KEY, 'pending']);

        return (int) $stmt->fetchColumn();
    }
}
