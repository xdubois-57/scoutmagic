<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Task\SendResponseDigestHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * @group database
 */
class SendResponseDigestHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt('author@test.com'), $this->encryption->blindIndex('author@test.com')]);
        $authorId = (int) $this->pdo->lastInsertId();

        $articleId = (new ArticleRepository($this->pdo))->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $authorId);
        $this->formId = (new FormRepository($this->pdo))->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', true, null);

        $settingRepository = new SettingRepository($this->pdo);
        $settingService = new SettingService($settingRepository);
        $settingService->register('site_name', 'Test Unit', 'text', 'label', 'desc');
        $settingService->register('base_url', 'https://example.com', 'text', 'label', 'desc');
    }

    private function buildContext(MailService $mailService): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    public function testSendsDigestForNewResponsesAndReschedulesItself(): void
    {
        (new FormResponseRepository($this->pdo, $this->encryption))->create($this->formId, null, null, 'parent@test.com', [], null, null);

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send');

        $handler = new SendResponseDigestHandler();
        $handler->handle([], $this->buildContext($mailService));

        $scheduled = (new SchedulerService(new SchedulerRepository($this->pdo)))->find('news', 'send_response_digest', SendResponseDigestHandler::REFERENCE);
        $this->assertNotNull($scheduled);
    }

    public function testSendsNothingWithNoNewResponses(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');

        $handler = new SendResponseDigestHandler();
        $handler->handle([], $this->buildContext($mailService));
    }
}
