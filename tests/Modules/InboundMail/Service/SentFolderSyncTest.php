<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageDirection;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AnalysisResultApplier;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\FakeOutboundConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The box's sent mail (#720, step 3): read for the consumers that declare
 * `HandlesOutboundMail`, never offered to the others, and stored only when
 * one of them files it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SentFolderSyncTest extends TestCase
{
    private \PDO $pdo;
    private InboundMailboxRepository $mailboxes;
    private InboundMessageRepository $messages;
    private MessageConsumerRegistry $registry;
    private FakeMailboxClient $client;
    private MailboxSyncService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);
        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $this->registry = new MessageConsumerRegistry();
        $this->client = new FakeMailboxClient();

        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        $this->service = new MailboxSyncService(
            $this->mailboxes,
            $this->messages,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new AnalysisResultApplier($this->messages)
        );

        $this->mailboxId = $this->mailboxes->create(
            'Locations',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'un-mot-de-passe',
            ['INBOX'],
            true
        );
    }

    public function testTheFolderTheServerMarksSentIsReadAsSentMail(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $rental = $this->outbound(fn(CandidateMessage $m) => AnalysisResult::linkedTo('rental', 'LOC-2027-K7Q2MX', LinkOrigin::RECIPIENT));

        $this->sync();

        $this->assertCount(1, $rental->offered);
        $this->assertTrue($rental->offered[0]->isSent());
        $this->assertSame('Envoyés', $rental->offered[0]->folder);

        $stored = $this->messages->findForReference('rental', 'LOC-2027-K7Q2MX');
        $this->assertCount(1, $stored);
        $this->assertSame(MessageDirection::SENT, $stored[0]->direction);
        $this->assertSame(['jeanne@example.be'], $stored[0]->toEmails);
    }

    public function testAReceivedMessageIsStoredAsReceived(): void
    {
        $this->addMessage('INBOX', 1, 'in-1@example.be');
        $this->outbound(fn(CandidateMessage $m) => AnalysisResult::linkedTo('rental', 'LOC-2027-K7Q2MX', LinkOrigin::SENDER));

        $this->sync();

        $stored = $this->messages->findForReference('rental', 'LOC-2027-K7Q2MX');
        $this->assertSame(MessageDirection::RECEIVED, $stored[0]->direction);
        $this->assertFalse($stored[0]->isSent());
    }

    public function testSentMailNoConsumerFilesIsNotStored(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'fournisseur@example.be');
        $rental = $this->outbound();

        $outcome = $this->sync();

        $this->assertCount(1, $rental->offered, 'It was read and offered…');
        $this->assertSame(0, $this->countMessages(), '…and, filed nowhere, it was not kept.');
        $this->assertSame(1, $outcome->messagesSeen);
        $this->assertSame(0, $outcome->messagesStored);
    }

    public function testAConsumerThatDoesNotDeclareSentMailIsNeverOfferedAny(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('INBOX', 1, 'in-1@example.be');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $camps = $this->inbound('camps');
        $rental = $this->outbound();

        $this->sync();

        $this->assertCount(1, $camps->offered);
        $this->assertFalse($camps->offered[0]->isSent());
        $this->assertCount(2, $rental->offered);
    }

    public function testABoxOpenedToNoOutboundConsumerNeverListsNorReadsItsSentFolder(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $this->inbound('camps');
        $this->inbound('finance');

        $this->sync();

        $this->assertNotContains('listFolders', $this->client->calls);
        $this->assertNotContains('folderState:Envoyés', $this->client->calls);
    }

    public function testAServerThatMarksNoFolderLeavesSentMailUnread(): void
    {
        // Names are never guessed: « Sent » is not read because of its name.
        $this->addMessage('Sent', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $rental = $this->outbound();

        $this->sync();

        $this->assertSame([], $rental->offered);
        $this->assertNotContains('folderState:Sent', $this->client->calls);
    }

    public function testTheFolderTheOperatorNamesIsReadWithoutAskingTheServer(): void
    {
        $this->addMessage('INBOX.Sent', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $this->mailboxes->setSentFolder($this->mailboxId, 'INBOX.Sent');
        $rental = $this->outbound();

        $this->sync();

        $this->assertCount(1, $rental->offered);
        $this->assertTrue($rental->offered[0]->isSent());
        $this->assertNotContains('listFolders', $this->client->calls);
    }

    public function testASentFolderAlsoWatchedIsReadOnceAsReceived(): void
    {
        // An operator who once ticked « Envoyés » among the watched folders
        // keeps what they had: one reading, as received.
        $this->mailboxes->update($this->mailboxId, 'Locations', 'imap.test', 993, 'ssl', 'locations@unite.be', ['INBOX', 'Envoyés'], true);
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $rental = $this->outbound();

        $this->sync();

        $this->assertCount(1, $rental->offered);
        $this->assertFalse($rental->offered[0]->isSent());
    }

    public function testAMessageTheUnitSentToItsOwnBoxIsKeptOnceAsReceived(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('INBOX', 1, 'self@unite.be', from: 'locations@unite.be', to: 'locations@unite.be');
        $this->addMessage('Envoyés', 1, 'self@unite.be', from: 'locations@unite.be', to: 'locations@unite.be');
        $this->outbound(fn(CandidateMessage $m) => AnalysisResult::linkedTo('rental', 'LOC-2027-K7Q2MX', LinkOrigin::REFERENCE));

        $this->sync();

        $this->assertSame(1, $this->countMessages());
        $stored = $this->messages->findForReference('rental', 'LOC-2027-K7Q2MX');
        $this->assertSame(MessageDirection::RECEIVED, $stored[0]->direction);
    }

    public function testTheSameMessageReadSentFirstEndsUpReceivedToo(): void
    {
        // The other order: the sent copy is filed on one pass, the inbox
        // copy arrives on a later one. Still one row, and received.
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'self@unite.be', from: 'locations@unite.be', to: 'locations@unite.be');
        $this->outbound(fn(CandidateMessage $m) => AnalysisResult::linkedTo('rental', 'LOC-2027-K7Q2MX', LinkOrigin::REFERENCE));
        $this->sync();
        $this->assertSame(MessageDirection::SENT, $this->messages->findForReference('rental', 'LOC-2027-K7Q2MX')[0]->direction);

        $this->addMessage('INBOX', 1, 'self@unite.be', from: 'locations@unite.be', to: 'locations@unite.be');
        $this->sync();

        $this->assertSame(1, $this->countMessages());
        $this->assertSame(MessageDirection::RECEIVED, $this->messages->findForReference('rental', 'LOC-2027-K7Q2MX')[0]->direction);
    }

    public function testTheSentFolderHasItsOwnCursor(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $rental = $this->outbound();

        $this->sync();
        $this->sync();

        $this->assertCount(1, $rental->offered, 'A second pass does not read the same sent message again.');
        $this->assertSame(1, $this->mailboxes->findCursor($this->mailboxId, 'Envoyés')->lastUid);
    }

    public function testSentMailIsNeverCountedAsUnread(): void
    {
        $this->client->markSent('Envoyés');
        $this->addMessage('INBOX', 1, 'in-1@example.be');
        $this->addMessage('Envoyés', 1, 'sent-1@unite.be', from: 'locations@unite.be', to: 'jeanne@example.be');
        $this->outbound(fn(CandidateMessage $m) => AnalysisResult::linkedTo('rental', 'LOC-2027-K7Q2MX', LinkOrigin::SENDER));

        $this->sync();

        $this->assertSame(
            ['LOC-2027-K7Q2MX' => 1],
            $this->messages->countLinksAfter('rental', ['LOC-2027-K7Q2MX' => 0])
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function addMessage(
        string $folder,
        int $uid,
        string $messageId,
        string $from = 'jeanne@example.be',
        string $to = 'locations@unite.be'
    ): void {
        $this->client->addRawMessage($folder, $uid, InboundMailTestHelper::rawMessage([
            'From' => $from,
            'To' => $to,
            'Subject' => 'Votre séjour',
            'Message-ID' => '<' . $messageId . '>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], 'Bonjour'));
    }

    /** @param (\Closure(CandidateMessage): AnalysisResult)|null $answer */
    private function outbound(?\Closure $answer = null): FakeOutboundConsumer
    {
        $consumer = new FakeOutboundConsumer('rental', $answer);
        $this->registry->register($consumer);

        return $consumer;
    }

    private function inbound(string $id): FakeMessageConsumer
    {
        $consumer = new FakeMessageConsumer($id);
        $this->registry->register($consumer);

        return $consumer;
    }

    private function sync(): \Modules\InboundMail\Service\SyncOutcome
    {
        $mailbox = $this->mailboxes->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        return $this->service->syncMailbox($mailbox, new \DateTimeImmutable('2027-07-12 10:00:00'));
    }

    private function countMessages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn();
    }
}
