<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisJournal;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * « Relancer l'analyse » — offering the mail nobody could attribute to a
 * module again, with what the site knows today.
 *
 * A message is analysed once, on arrival, against what the site knew that
 * day. The knowledge moves: a chief attaches one e-mail of a thread to a
 * stay and the rest of that thread becomes attributable, a place is
 * created and a farmer's address starts matching, a contact is added to a
 * camp. None of that reached back to the mail already collected, and
 * before this there was no way to make it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReanalyzeUnlinkedTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $consumers;
    private InboundMailService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->consumers = new MessageConsumerRegistry();
        $this->service = new InboundMailService(
            $this->messages,
            $mailboxes,
            new FileRepository($this->pdo),
            $this->consumers,
            null,
            new AnalysisJournal(new JournalService(new JournalRepository($this->pdo)))
        );

        $this->mailboxId = $mailboxes->create(
            'Camps',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'camps@unite.be',
            'secret',
            ['INBOX'],
            true
        );
    }

    public function testAMessageNobodyCouldAttributeIsOfferedAgain(): void
    {
        $messageId = $this->storeMessage('again@mail');
        $this->consumers->register(new FakeMessageConsumer(
            'camps',
            fn(): AnalysisResult => new AnalysisResult([new MessageLink('camps', 'camp-51', LinkOrigin::THREAD)])
        ));

        $report = $this->service->reanalyzeUnlinked('camps');

        $this->assertSame(['examined' => 1, 'linked' => 1, 'proposed' => 0], $report);
        $links = $this->messages->findLinksForMessage($messageId);
        $this->assertCount(1, $links);
        $this->assertSame('camp-51', $links[0]->businessReference);
    }

    public function testAMessageAlreadyAttachedIsLeftAlone(): void
    {
        // Somebody's reading already settled it; offering it around again
        // could only produce a second claim on what is not in doubt.
        $messageId = $this->storeMessage('settled@mail');
        $this->messages->addLink($messageId, 'rental', 'LOC-2027-0042', LinkOrigin::MANUAL, 0, null);
        $seen = 0;
        $this->consumers->register(new FakeMessageConsumer('camps', function () use (&$seen): AnalysisResult {
            $seen++;

            return AnalysisResult::nothing();
        }));

        $this->assertSame(0, $this->service->reanalyzeUnlinked('camps')['examined']);
        $this->assertSame(0, $seen);
    }

    public function testOnlyTheAskingModuleIsAsked(): void
    {
        // A chief pressing a button on their own module's screen must not
        // quietly make another module claim mail.
        $this->storeMessage('scoped@mail');
        $rental = new FakeMessageConsumer(
            'rental',
            fn(): AnalysisResult => new AnalysisResult([new MessageLink('rental', 'LOC-1', LinkOrigin::AI)])
        );
        $this->consumers->register($rental);
        $this->consumers->register(new FakeMessageConsumer('camps'));

        $this->service->reanalyzeUnlinked('camps');

        $this->assertSame([], $rental->offered);
    }

    public function testTheConsumerIsToldSoItCanFileWhatTheMessageCarried(): void
    {
        // `onLinked()` is where a module turns a message's attachments into
        // documents of its own — the whole reason an association is worth
        // creating.
        $this->storeMessage('filed@mail');
        $consumer = new FakeMessageConsumer(
            'camps',
            fn(): AnalysisResult => new AnalysisResult([new MessageLink('camps', 'camp-51', LinkOrigin::SENDER)])
        );
        $this->consumers->register($consumer);

        $this->service->reanalyzeUnlinked('camps');

        $this->assertCount(1, $consumer->linked);
    }

    public function testAPropositionIsCountedAsOne(): void
    {
        $this->storeMessage('proposed@mail');
        $this->consumers->register(new FakeMessageConsumer(
            'camps',
            fn(): AnalysisResult => AnalysisResult::proposing(
                new MessageCandidate('camp-51', 'Grand camp', 'sender_window', 'parce que')
            )
        ));

        $report = $this->service->reanalyzeUnlinked('camps');

        $this->assertSame(1, $report['proposed']);
        $this->assertSame(0, $report['linked']);
    }

    public function testTheMessageIsHandedBackWithWhatTheArrivalPassReads(): void
    {
        // The re-run rebuilds the candidate the arrival pass saw, or a
        // consumer whose whole signal is the sender or the thread would be
        // asked a question it cannot answer.
        $this->storeMessage('shape@mail', 'parent@mail');
        $consumer = new FakeMessageConsumer('camps');
        $this->consumers->register($consumer);

        $this->service->reanalyzeUnlinked('camps');

        $this->assertCount(1, $consumer->offered);
        $this->assertSame('jeanne@example.be', $consumer->offered[0]->fromEmail);
        $this->assertSame('parent@mail', $consumer->offered[0]->inReplyTo);
        $this->assertSame($this->mailboxId, $consumer->offered[0]->mailboxId);
    }

    public function testTheSlowHalfIsHandedToTheHourlyTask(): void
    {
        // Reading an attachment's text or calling a model is not something
        // a chief should watch a page spin through.
        $messageId = $this->storeMessage('deferred@mail');
        $this->messages->markStoredAnalysisDone($messageId, new \DateTimeImmutable());
        $this->consumers->register(new FakeMessageConsumer('camps'));

        $this->service->reanalyzeUnlinked('camps');

        $this->assertSame([$messageId], $this->messages->findMessagesAwaitingStoredAnalysis(10));
    }

    public function testAModuleWithNoConsumerRegisteredChangesNothing(): void
    {
        $this->storeMessage('absent@mail');

        $this->assertSame(
            ['examined' => 0, 'linked' => 0, 'proposed' => 0],
            $this->service->reanalyzeUnlinked('camps')
        );
    }

    public function testAConsumerThatThrowsIsSkippedAndSaidSo(): void
    {
        $this->storeMessage('boom@mail');
        $this->consumers->register(new FakeMessageConsumer('camps', function (): AnalysisResult {
            throw new \RuntimeException('boum');
        }));

        $report = $this->service->reanalyzeUnlinked('camps');

        $this->assertSame(1, $report['examined']);
        $this->assertSame(0, $report['linked']);
        $this->assertSame(
            'inbound_analysis_failed',
            (new JournalRepository($this->pdo))->search()[0]['event_type']
        );
    }

    public function testTheRunIsBounded(): void
    {
        foreach (range(1, 4) as $n) {
            $this->storeMessage("bulk{$n}@mail");
        }
        $this->consumers->register(new FakeMessageConsumer('camps'));

        $this->assertSame(2, $this->service->reanalyzeUnlinked('camps', 2)['examined']);
    }

    private function storeMessage(string $messageId, ?string $inReplyTo = null): int
    {
        static $uid = 400;

        return $this->messages->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            subject: 'Réservation',
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }
}
