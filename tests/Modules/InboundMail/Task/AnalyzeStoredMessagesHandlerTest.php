<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Task;

use Core\Database\Connection;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MailboxScope;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Api\ReadMode;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Task\AnalyzeStoredMessagesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The deferred pass, as the scheduler actually runs it.
 *
 * `ConsumerLifecycleTest` proves what the repository offers the pass and
 * what the applier does with an answer. This proves the handler itself:
 * that it asks, that it marks, and — the part nothing else covers — that
 * it puts itself back on the schedule whatever happened.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AnalyzeStoredMessagesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private EncryptionService $encryption;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $this->encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $this->encryption);
        $this->mailboxId = $mailboxes->create(
            'Unité',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            ['INBOX'],
            true
        );

        // The box is open to the two consumers these tests register — the
        // deferred pass narrows the question to what the box allows, as
        // the arrival pass does, and a consumer with no row is never asked.
        $mailboxes->saveScope($this->mailboxId, new MailboxScope('rental', true, ReadMode::NONE));
        $mailboxes->saveScope($this->mailboxId, new MailboxScope('camps', true, ReadMode::NONE));
    }

    public function testAConsumerTheBoxIsNotOpenToIsNeverHandedTheStoredMessage(): void
    {
        // IT-05 held on arrival and not here: every registered consumer
        // got the stored content of every message, on a box whose
        // operator had answered « n'analyse pas » for it.
        $messageId = $this->storeMessage('scoped@mail');
        $asked = false;
        $consumer = new FakeMessageConsumer(
            'finance',
            null,
            function () use (&$asked): AnalysisResult {
                $asked = true;

                return new AnalysisResult([new MessageLink('finance', 'account-1', LinkOrigin::AI)]);
            }
        );

        $this->runPass($consumer);

        $this->assertFalse($asked, 'a consumer the box was never opened to must not see its mail');
        $this->assertSame([], $this->messages->findLinksForMessage($messageId));
        $this->assertSame(
            [],
            $this->messages->findMessagesAwaitingStoredAnalysis(10),
            'the message is still marked as having been through the pass'
        );
    }

    public function testTheDeferredPassAppliesWhatAConsumerFindsInTheStoredMessage(): void
    {
        $messageId = $this->storeMessage('deferred@mail');
        $consumer = new FakeMessageConsumer(
            'rental',
            null,
            fn(): AnalysisResult => new AnalysisResult(
                [new MessageLink('rental', 'LOC-2027-0042', LinkOrigin::AI)]
            )
        );

        $this->runPass($consumer);

        $links = $this->messages->findLinksForMessage($messageId);
        $this->assertCount(1, $links);
        $this->assertSame('LOC-2027-0042', $links[0]->businessReference);
    }

    /**
     * The half this pass was missing.
     *
     * `apply()` writes the rows and reports which associations are new;
     * somebody then has to tell the consumer, and only the ARRIVAL pass
     * did. So a stay created automatically from a booking e-mail got the
     * association and NOT the contract that arrived with it: `onLinked()`
     * — the one place a consumer turns a message's attachments into
     * documents of its own — was never reached on the only path that
     * creates such a stay.
     */
    public function testAConsumerIsToldAboutTheAssociationTheDeferredPassCreated(): void
    {
        $messageId = $this->storeMessage('filed@mail');
        $consumer = new FakeMessageConsumer(
            'camps',
            null,
            fn(): AnalysisResult => new AnalysisResult(
                [new MessageLink('camps', 'camp-51', LinkOrigin::SENDER)]
            )
        );

        $this->runPass($consumer);

        $this->assertCount(1, $consumer->linked);
        $this->assertSame($messageId, $consumer->linked[0][0]->id);
        $this->assertSame('camp-51', $consumer->linked[0][1]->businessReference);
    }

    public function testTheConsumerIsToldOnlyAboutAssociationsThatAreActuallyNew(): void
    {
        // `onLinked()` fires once per association, ever: a re-analysis must
        // not make a module file the same attachment a second time.
        $this->storeMessage('twice@mail');
        $consumer = new FakeMessageConsumer(
            'camps',
            null,
            fn(): AnalysisResult => new AnalysisResult(
                [new MessageLink('camps', 'camp-51', LinkOrigin::SENDER)]
            )
        );

        $this->runPass($consumer);
        $this->messages->queueAllForStoredAnalysis();
        $this->runPass($consumer);

        $this->assertCount(1, $consumer->linked);
    }

    public function testAConsumerThatThrowsWhileFilingIsSaidSoRatherThanSwallowed(): void
    {
        // The stay exists and its contract was not filed. Nothing else in
        // the application will ever complain about that, which is exactly
        // why it belongs in the journal.
        $this->storeMessage('boom@mail');
        $consumer = new FakeMessageConsumer(
            'camps',
            null,
            fn(): AnalysisResult => new AnalysisResult(
                [new MessageLink('camps', 'camp-51', LinkOrigin::SENDER)]
            ),
            throwsOnLinked: true
        );

        $this->runPass($consumer);

        $entry = $this->journalEntry('inbound_analysis_failed');
        $context = json_decode((string) $entry['context'], true);
        $this->assertSame('camps', $context['consumer']);
        $this->assertSame('classement', $context['pass']);
    }

    /**
     * @return array<string, mixed>
     */
    private function journalEntry(string $type): array
    {
        foreach ((new JournalRepository($this->pdo))->search() as $entry) {
            if ($entry['event_type'] === $type) {
                return $entry;
            }
        }

        $this->fail('no journal entry of type ' . $type);
    }

    public function testAMessageIsPutThroughTheDeferredPassOnlyOnce(): void
    {
        // `stored_analysis_at` is the marker, and nothing re-analyses on its
        // own: propositions appearing and disappearing with nobody able to
        // say why is worse than none at all.
        $this->storeMessage('once@mail');
        $seen = 0;
        $consumer = new FakeMessageConsumer(
            'rental',
            null,
            function () use (&$seen): AnalysisResult {
                $seen++;

                return AnalysisResult::nothing();
            }
        );

        $this->runPass($consumer);
        $this->runPass($consumer);

        $this->assertSame(1, $seen);
    }

    public function testTheTaskPutsItselfBackOnTheScheduleEvenWithNoConsumerAtAll(): void
    {
        // Unconditional, and the reason is subtle: the runner marks this
        // task done only after handle() returns, so a guard would find it
        // still pending, skip, and end the chain after one run.
        $this->runPass(null);

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions
                  WHERE task_key = '" . AnalyzeStoredMessagesHandler::TASK_KEY . "'"
            )->fetchColumn()
        );
    }

    public function testAMessageWhoseAnalysisThrowsDoesNotBlockTheQueueBehindIt(): void
    {
        // Marked before the work, not after — the same reason the sync
        // cursor advances past a message it could not use.
        $this->storeMessage('boom@mail');
        $this->runPass(new FakeMessageConsumer(
            'rental',
            null,
            function (): AnalysisResult {
                throw new \RuntimeException('analysis failed');
            }
        ));

        $this->assertSame(
            0,
            count($this->messages->findMessagesAwaitingStoredAnalysis(10)),
            'a message that threw is not offered again at the head of the queue'
        );
    }

    private function runPass(?FakeMessageConsumer $consumer): void
    {
        $registry = new MessageConsumerRegistry();
        if ($consumer !== null) {
            $registry->register($consumer);
        }

        (new AnalyzeStoredMessagesHandler(
            $registry,
            new \Modules\InboundMail\Service\AnalysisJournal(
                new JournalService(new JournalRepository($this->pdo))
            )
        ))->handle([], new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        ));
    }

    private function storeMessage(string $messageId): int
    {
        static $uid = 200;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Facture',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }
}
