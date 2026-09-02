<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\TicketService;
use Modules\News\Task\SendPendingTicketsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;

/**
 * The catch-up that raising a form's ticketing switch schedules.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SendPendingTicketsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private FormRepository $forms;
    private FormResponseRepository $responses;
    private int $formId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt('author@test.com', 'user_accounts.email'), $this->encryption->blindIndex('author@test.com', 'email')]);
        $authorId = (int) $this->pdo->lastInsertId();

        $articleId = (new ArticleRepository($this->pdo))->create('Souper spaghetti', Article::VISIBILITY_PUBLIC, true, null, null, $authorId);
        $this->forms = new FormRepository($this->pdo);
        $this->formId = $this->forms->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, true, '2026-03-14', 'Salle paroissiale'
        );
        $this->responses = new FormResponseRepository($this->pdo, $this->encryption);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('site_name', 'Test Unit', 'text', 'label', 'desc');
        $settingService->register('base_url', 'https://example.com', 'text', 'label', 'desc');
    }

    private function context(MailService $mailService): TaskContext
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

    private function ticketedResponse(string $email): int
    {
        $id = $this->responses->create($this->formId, null, null, $email, [], null, null);
        (new TicketService($this->responses))->issueFor($this->responses->findById($id));

        return $id;
    }

    public function testItPostsOneTicketPerNamedResponse(): void
    {
        $first = $this->ticketedResponse('a@test.com');
        $second = $this->ticketedResponse('b@test.com');

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->exactly(2))->method('send');

        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => [$first, $second]],
            $this->context($mailService)
        );
    }

    public function testItPostsNothingToAResponseTheControllerDidNotName(): void
    {
        // Somebody who answered between the switch being flipped and this
        // run already got their ticket inside their ordinary confirmation.
        // Re-deriving the batch from « every response of the form » would
        // post them a second one.
        $named = $this->ticketedResponse('a@test.com');
        $this->ticketedResponse('later@test.com');

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send');

        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => [$named]],
            $this->context($mailService)
        );
    }

    public function testItPostsNothingOnceTheSwitchIsLoweredAgain(): void
    {
        $responseId = $this->ticketedResponse('a@test.com');
        $this->forms->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, false
        );

        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');

        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => [$responseId]],
            $this->context($mailService)
        );
    }

    public function testAResponseThatMovedOrVanishedIsSkippedRatherThanFatal(): void
    {
        // The payload survives a deployment in the database, so a row it
        // names may be gone by the time it runs.
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');

        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => [99999]],
            $this->context($mailService)
        );
    }

    public function testAnEmptyPayloadDoesNothingAtAll(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');

        (new SendPendingTicketsHandler())->handle([], $this->context($mailService));
    }

    public function testTheTicketCarriesItsReferenceAndTheEventDetails(): void
    {
        $responseId = $this->ticketedResponse('a@test.com');
        $reference = TicketService::format((string) $this->responses->findById($responseId)?->ticketReference);

        $sentText = null;
        $sentHtml = null;
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text) use (&$sentHtml, &$sentText): void {
                $sentHtml = $html;
                $sentText = $text;
            }
        );

        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => [$responseId]],
            $this->context($mailService)
        );

        $this->assertStringContainsString($reference, (string) $sentHtml);
        // The plain-text half has to stand on its own: most mail clients
        // block images by default.
        $this->assertStringContainsString($reference, (string) $sentText);
        $this->assertStringContainsString('14/03/2026', (string) $sentText);
        $this->assertStringContainsString('Salle paroissiale', (string) $sentText);
    }
}
