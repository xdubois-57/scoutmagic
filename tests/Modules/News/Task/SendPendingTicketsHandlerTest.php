<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
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

    /**
     * Every message the transport accepted, in order.
     *
     * @var list<array{to: string, subject: string, html: string, text: string}>
     */
    private array $sent = [];

    /**
     * The addresses the transport refuses, the way the real one refuses a
     * suspended or malformed recipient.
     *
     * @var list<string>
     */
    private array $refused = [];

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

    /**
     * A transport that keeps what it was handed, and refuses what the
     * real one refuses.
     *
     * **Why not `expects($this->exactly(2))->method('send')`.** That
     * counts calls and looks at nothing else, so it is satisfied by a
     * handler that posts both tickets to a stranger — mutating
     * `TicketMailService::sendTicketEmail()` to send every ticket to one
     * fixed address left seven of this class's eight tests green. Reading
     * the addresses back turns the count into a statement about who was
     * written to.
     *
     * **And why it can refuse.** `MailService::send()` is documented
     * « @throws MailException on failure », and a double that cannot
     * throw describes a post office that never loses a letter. The
     * branch this handler is largely about — journal the failure, carry
     * on with the batch — was reachable by no test here: narrowing its
     * `catch` to `SuppressedRecipientException`, or renaming the event it
     * journals, both left the class green.
     */
    private function transport(): MailService
    {
        // A stub rather than a mock, and PHPUnit 13 asks for exactly that
        // when no expectation is configured: nothing here is verified on
        // the double itself any more — the verdicts are read off what it
        // recorded.
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text): void {
                if (in_array($to, $this->refused, true)) {
                    throw new MailException('Transport refusé pour cette adresse');
                }

                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
            }
        );

        return $mailService;
    }

    /**
     * Who received a ticket, in the order the handler posted them.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_column($this->sent, 'to');
    }

    /**
     * Runs the catch-up over the named responses.
     *
     * @param list<int> $responseIds
     */
    private function handle(array $responseIds): void
    {
        (new SendPendingTicketsHandler())->handle(
            ['form_id' => $this->formId, 'response_ids' => $responseIds],
            $this->context($this->transport())
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

        $this->handle([$first, $second]);

        $this->assertSame(['a@test.com', 'b@test.com'], $this->recipients());
    }

    /**
     * Issue #246: the scheduler marks a task done only after handle()
     * returns, so an abrupt stop mid-batch runs the whole payload again —
     * and hasTicket() is « a une référence », not « son billet est parti ».
     */
    public function testAReplayOfTheSameBatchPostsNothingASecondTime(): void
    {
        $first = $this->ticketedResponse('a@test.com');
        $second = $this->ticketedResponse('b@test.com');

        $this->handle([$first, $second]);
        $this->assertSame(['a@test.com', 'b@test.com'], $this->recipients());

        // The same list again: what the transport holds must not grow.
        $this->handle([$first, $second]);
        $this->assertSame(
            ['a@test.com', 'b@test.com'],
            $this->recipients(),
            'The replay posted a second ticket to somebody who already had theirs.'
        );
    }

    public function testAResponseTheFirstRunNeverReachedStillGetsItsTicket(): void
    {
        $first = $this->ticketedResponse('a@test.com');

        $this->handle([$first]);
        $this->assertSame(['a@test.com'], $this->recipients());

        // The run died before reaching this one; the replay names both.
        $second = $this->ticketedResponse('b@test.com');
        $this->handle([$first, $second]);

        $this->assertSame(['a@test.com', 'b@test.com'], $this->recipients());
    }

    public function testItPostsNothingToAResponseTheControllerDidNotName(): void
    {
        // Somebody who answered between the switch being flipped and this
        // run already got their ticket inside their ordinary confirmation.
        // Re-deriving the batch from « every response of the form » would
        // post them a second one.
        $named = $this->ticketedResponse('a@test.com');
        $this->ticketedResponse('later@test.com');

        $this->handle([$named]);

        $this->assertSame(['a@test.com'], $this->recipients());
    }

    public function testItPostsNothingOnceTheSwitchIsLoweredAgain(): void
    {
        $responseId = $this->ticketedResponse('a@test.com');
        $this->forms->update(
            $this->formId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', false, null, false
        );

        $this->handle([$responseId]);

        $this->assertSame([], $this->recipients());
    }

    public function testAResponseThatMovedOrVanishedIsSkippedRatherThanFatal(): void
    {
        // The payload survives a deployment in the database, so a row it
        // names may be gone by the time it runs.
        $this->handle([99999]);

        $this->assertSame([], $this->recipients());
    }

    public function testAnEmptyPayloadDoesNothingAtAll(): void
    {
        (new SendPendingTicketsHandler())->handle([], $this->context($this->transport()));

        $this->assertSame([], $this->recipients());
    }

    public function testTheTicketCarriesItsReferenceAndTheEventDetails(): void
    {
        $responseId = $this->ticketedResponse('a@test.com');
        $reference = TicketService::format((string) $this->responses->findById($responseId)?->ticketReference);

        $this->handle([$responseId]);

        $this->assertSame(['a@test.com'], $this->recipients());
        $this->assertStringContainsString($reference, $this->sent[0]['html']);
        // The plain-text half has to stand on its own: most mail clients
        // block images by default.
        $this->assertStringContainsString($reference, $this->sent[0]['text']);
        $this->assertStringContainsString('14/03/2026', $this->sent[0]['text']);
        $this->assertStringContainsString('Salle paroissiale', $this->sent[0]['text']);
    }

    /**
     * One bounce does not cost the rest of the batch their tickets.
     *
     * The handler's own docblock promises it — « A failure to send is
     * journaled and the batch continues » — and it is the reason the
     * e-mails were moved out of the controller in the first place: an
     * author who ticks a checkbox must not lose a hundred families'
     * tickets to one bad address halfway through.
     */
    public function testOneRefusedTicketDoesNotCostTheOthersTheirs(): void
    {
        $first = $this->ticketedResponse('a@test.com');
        $refused = $this->ticketedResponse('refuse@test.com');
        $last = $this->ticketedResponse('c@test.com');
        $this->refused = ['refuse@test.com'];

        $this->handle([$first, $refused, $last]);

        $this->assertSame(['a@test.com', 'c@test.com'], $this->recipients());
    }

    /**
     * The journal names the row, never the family.
     *
     * The entry is written on the failure path, and its context is three
     * identifiers precisely so a refused address does not end up in a
     * table that outlives the send (SECURITY.md § Journalisation).
     */
    public function testARefusedTicketIsJournalledByIdentifiersAlone(): void
    {
        // Two responses nobody names, for the sole purpose of pushing the
        // third one's id past the article's and the form's — both 1 in a
        // fresh database. Without them, journalling `article_id` where
        // `response_id` belongs reads as correct, since 1 == 1: verified
        // by making exactly that substitution, which stayed green.
        $this->ticketedResponse('quelconque-a@test.com');
        $this->ticketedResponse('quelconque-b@test.com');
        $responseId = $this->ticketedResponse('refuse@test.com');
        $this->assertGreaterThan(1, $responseId);
        $this->refused = ['refuse@test.com'];

        $this->handle([$responseId]);

        $statement = $this->pdo->prepare(
            'SELECT event_type, description, context FROM event_log WHERE category = ?'
        );
        $statement->execute(['news']);
        $entries = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $entries);
        $this->assertSame('ticket_email_failed', $entries[0]['event_type']);
        // The decoded key, not a substring of the JSON: with one article,
        // one form and one response in a fresh database, every identifier
        // is 1, so « le contexte contient "1" » holds just as well when
        // response_id has been dropped altogether. Verified by removing it
        // — the substring form stayed green.
        $context = json_decode((string) $entries[0]['context'], true);
        $this->assertIsArray($context);
        $this->assertSame($responseId, $context['response_id'] ?? null);
        $this->assertStringNotContainsString(
            'refuse@test.com',
            (string) $entries[0]['context'] . (string) $entries[0]['description'],
            'The refused address was written into a table that outlives the send.'
        );
    }

    /**
     * A refusal is not retried, and that is the design rather than an
     * oversight.
     *
     * The claim is taken before the transport, never after: a claimed
     * send that then fails leaves the holder their ticket on the
     * responses screen and at the door, whereas claiming afterwards posts
     * the whole batch a second time on every restart. So a bounce costs
     * one e-mail, not one ticket — and a run that follows must not treat
     * the address as owed.
     */
    public function testARefusedTicketIsNotRetriedOnTheNextRun(): void
    {
        $responseId = $this->ticketedResponse('refuse@test.com');
        $this->refused = ['refuse@test.com'];
        $this->handle([$responseId]);
        $this->assertSame([], $this->recipients());

        // The address works again, and the payload is replayed.
        $this->refused = [];
        $this->handle([$responseId]);

        $this->assertSame([], $this->recipients());
    }
}
