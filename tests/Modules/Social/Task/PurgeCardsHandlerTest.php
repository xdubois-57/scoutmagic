<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\UserAccountRepository;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Task\PurgeCardsHandler;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The daily purge of cards whose hour is over — under the site's real
 * `storage/social/cards/`, as the web path writes them.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PurgeCardsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->storage = sys_get_temp_dir() . '/social-storage-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $directory = $this->storage . '/' . CardService::DIRECTORY;
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach ([$directory, dirname($directory), $this->storage] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testExpiredCardsGoAndTheChainIsRearmed(): void
    {
        $journal = new RecordingJournalRepository();
        $cards = new CardService(
            new CardRepository($this->pdo),
            new CardRenderer(),
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService($journal),
            $this->storage . '/' . CardService::DIRECTORY
        );
        $cards->issue(H::groupPhoto(), 'Old', 'a.be', true, new \DateTimeImmutable('-3 hours'));
        $cards->issue(H::groupPhoto(), 'Live', 'a.be', true, new \DateTimeImmutable());

        (new PurgeCardsHandler())->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            H::encryption(),
            $this->createStub(MailService::class),
            new JournalService($journal),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, H::encryption()),
            $this->storage
        ));

        $this->assertCount(1, glob($this->storage . '/' . CardService::DIRECTORY . '/*.jpg') ?: []);
        $this->assertSame(1, $journal->countOf('cards_purged'));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduled_actions WHERE module_id = ? AND task_key = ?');
        $stmt->execute(['social', PurgeCardsHandler::TASK_KEY]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }
}
