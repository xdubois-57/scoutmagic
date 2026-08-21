<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The boundaries of the inter-module API (§7.11).
 *
 * The point of this file is the thing that is NOT tested here because it
 * does not exist: there is no way to ask this service for "all messages",
 * for a mailbox's contents, or for anything not scoped to one consumer and
 * one business reference. A manager who may open a booking must not thereby
 * gain a window onto the unit's mailbox, and the enforcement is that the
 * query was never written.
 *
 * What IS tested is that the scoping actually holds when a caller supplies
 * the wrong half of a pair — a real message id with somebody else's
 * reference, or another module's consumer id.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InboundMailServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMessageRepository $messageRepository;
    private InboundMailboxRepository $mailboxRepository;
    private InboundMailService $service;
    private int $mailboxId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messageRepository = new InboundMessageRepository($this->pdo, $this->encryption);
        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $this->encryption);
        $this->service = new InboundMailService(
            $this->messageRepository,
            $this->mailboxRepository,
            new FileRepository($this->pdo)
        );

        $this->mailboxId = $this->mailboxRepository->create(
            'Locations',
            ProviderType::IMAP,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'secret',
            ['INBOX'],
            true
        );
    }

    private function storeMessage(
        string $reference = 'LOC-2027-0042',
        string $consumerId = 'rental',
        string $messageId = 'msg-1@example.be',
        string $subject = 'Demande'
    ): int {
        return $this->messageRepository->create(
            mailboxId: $this->mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: 10,
            consumerId: $consumerId,
            businessReference: $reference,
            linkOrigin: LinkOrigin::REFERENCE,
            messageId: $messageId,
            inReplyTo: null,
            subject: $subject,
            fromEmail: 'jeanne@example.be',
            fromName: 'Jeanne Martin',
            bodyText: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    private function storeFile(): int
    {
        return (new FileRepository($this->pdo))->create(
            'inbound_mail/attachments/' . bin2hex(random_bytes(8)) . '.pdf',
            'contrat.pdf',
            'application/pdf',
            1024,
            'intendant',
            'inbound_mail',
            null
        );
    }

    // ── Scoping ─────────────────────────────────────────────────────────

    public function testAMessageIsReachableFromItsOwnReference(): void
    {
        $this->storeMessage();

        $messages = $this->service->findForReference('rental', 'LOC-2027-0042');

        $this->assertCount(1, $messages);
        $this->assertSame('Demande', $messages[0]->subject);
    }

    public function testAnotherReferenceSeesNothing(): void
    {
        $this->storeMessage(reference: 'LOC-2027-0042');

        $this->assertSame([], $this->service->findForReference('rental', 'LOC-2027-0099'));
    }

    public function testAnotherConsumerSeesNothingEvenKnowingTheReference(): void
    {
        // Knowing a reference must not be enough: `finance` asking for
        // `rental`'s mail gets nothing, not the mail.
        $this->storeMessage(consumerId: 'rental');

        $this->assertSame([], $this->service->findForReference('finance', 'LOC-2027-0042'));
    }

    public function testAMessageIdAloneIsNeverEnoughToReadAMessage(): void
    {
        $id = $this->storeMessage(reference: 'LOC-2027-0042');

        $this->assertNotNull($this->service->findOneForReference('rental', 'LOC-2027-0042', $id));
        $this->assertNull($this->service->findOneForReference('rental', 'LOC-2027-0099', $id));
        $this->assertNull($this->service->findOneForReference('finance', 'LOC-2027-0042', $id));
    }

    public function testMessagesComeBackOldestFirst(): void
    {
        $this->messageRepository->create(
            $this->mailboxId, 'INBOX', 1, 11, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE,
            'b@x', null, 'Deuxième', 'jeanne@example.be', null, 'B', '<p>B</p>',
            new \DateTimeImmutable('2027-07-14 09:00:00')
        );
        $this->messageRepository->create(
            $this->mailboxId, 'INBOX', 1, 10, 'rental', 'LOC-2027-0042', LinkOrigin::REFERENCE,
            'a@x', null, 'Premier', 'jeanne@example.be', null, 'A', '<p>A</p>',
            new \DateTimeImmutable('2027-07-12 09:00:00')
        );

        $subjects = array_map(
            static fn($message) => $message->subject,
            $this->service->findForReference('rental', 'LOC-2027-0042')
        );

        $this->assertSame(['Premier', 'Deuxième'], $subjects);
    }

    // ── Detaching (§7.6, §7.7) ──────────────────────────────────────────

    public function testDetachingDeletesRatherThanLeavingAnOrphan(): void
    {
        // There is no unattached queue for it to fall into, so a row left
        // behind with no reference would be exactly the invisible archive
        // this module exists to avoid.
        $id = $this->storeMessage();

        $this->assertTrue($this->service->detach('rental', 'LOC-2027-0042', $id));
        $this->assertSame([], $this->service->findForReference('rental', 'LOC-2027-0042'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testDetachingWithTheWrongReferenceChangesNothing(): void
    {
        $id = $this->storeMessage();

        $this->assertFalse($this->service->detach('rental', 'LOC-2027-0099', $id));
        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0042'));
    }

    public function testDetachingRemovesTheAttachmentFileNothingElseUses(): void
    {
        $id = $this->storeMessage();
        $fileId = $this->storeFile();
        $this->messageRepository->addAttachment($id, $fileId, 'contrat.pdf', 'application/pdf', 1024, str_repeat('a', 64));

        $this->service->detach('rental', 'LOC-2027-0042', $id);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testDetachingKeepsAFileAnotherMessageDeduplicatedOnto(): void
    {
        // The same PDF arriving on two messages is one stored file (§7.8).
        // Deleting it with the first message would break the second.
        $first = $this->storeMessage(messageId: 'a@x');
        $second = $this->storeMessage(messageId: 'b@x');
        $fileId = $this->storeFile();
        $hash = str_repeat('a', 64);
        $this->messageRepository->addAttachment($first, $fileId, 'contrat.pdf', 'application/pdf', 1024, $hash);
        $this->messageRepository->addAttachment($second, $fileId, 'contrat.pdf', 'application/pdf', 1024, $hash);

        $this->service->detach('rental', 'LOC-2027-0042', $first);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    // ── Moving (§7.7) ───────────────────────────────────────────────────

    public function testMovingChangesTheReference(): void
    {
        $id = $this->storeMessage(reference: 'LOC-2027-0042');

        $this->assertTrue($this->service->move('rental', 'LOC-2027-0042', 'LOC-2027-0043', $id));
        $this->assertSame([], $this->service->findForReference('rental', 'LOC-2027-0042'));
        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0043'));
    }

    public function testMovingRequiresTheMessageToBeWhereTheCallerSaysItIs(): void
    {
        $id = $this->storeMessage(reference: 'LOC-2027-0042');

        $this->assertFalse($this->service->move('rental', 'LOC-2027-0099', 'LOC-2027-0043', $id));
        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0042'));
    }

    public function testAnotherConsumerCannotMoveAMessageItDoesNotOwn(): void
    {
        $id = $this->storeMessage(consumerId: 'rental');

        $this->assertFalse($this->service->move('finance', 'LOC-2027-0042', 'FAC-2027-0001', $id));
    }

    public function testMovingToTheSameReferenceIsRefusedRatherThanBeingANoOpWrite(): void
    {
        $id = $this->storeMessage();

        $this->assertFalse($this->service->move('rental', 'LOC-2027-0042', 'LOC-2027-0042', $id));
    }

    // ── Retention (§7.10) ───────────────────────────────────────────────

    public function testPurgingARemovesEverythingHeldForAnObject(): void
    {
        $this->storeMessage(messageId: 'a@x');
        $this->storeMessage(messageId: 'b@x');
        $this->storeMessage(reference: 'LOC-2027-0099', messageId: 'c@x');

        $this->assertSame(2, $this->service->purgeReference('rental', 'LOC-2027-0042'));
        $this->assertSame([], $this->service->findForReference('rental', 'LOC-2027-0042'));
        $this->assertCount(1, $this->service->findForReference('rental', 'LOC-2027-0099'));
    }

    public function testPurgingRemovesTheAttachmentFilesToo(): void
    {
        $id = $this->storeMessage();
        $fileId = $this->storeFile();
        $this->messageRepository->addAttachment($id, $fileId, 'contrat.pdf', 'application/pdf', 1024, str_repeat('a', 64));

        $this->service->purgeReference('rental', 'LOC-2027-0042');

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    // ── Availability ────────────────────────────────────────────────────

    public function testACollectingInstallSaysSo(): void
    {
        $this->assertTrue($this->service->isCollecting());
    }

    public function testAnInstallWithEveryBoxDisabledIsNotCollecting(): void
    {
        // A consumer uses this to decide whether to show a communications
        // tab that would otherwise always be empty.
        $this->mailboxRepository->setEnabled($this->mailboxId, false);

        $this->assertFalse($this->service->isCollecting());
    }

    // ── Encryption at rest (SECURITY.md §5) ─────────────────────────────

    public function testTheSubjectAndSenderAreNotReadableInTheDatabase(): void
    {
        $this->storeMessage(subject: 'Demande de Jeanne Martin');

        $row = $this->pdo->query('SELECT * FROM inbound_messages LIMIT 1')->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringNotContainsString('Jeanne', (string) $row['subject_encrypted']);
        $this->assertStringNotContainsString('jeanne@example.be', (string) $row['from_email_encrypted']);
        $this->assertStringNotContainsString('Bonjour', (string) $row['body_text_encrypted']);
    }

    public function testTheMailboxPasswordIsNotReadableInTheDatabase(): void
    {
        $row = $this->pdo->query('SELECT * FROM inbound_mailboxes LIMIT 1')->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringNotContainsString('secret', (string) $row['password_encrypted']);
        $this->assertStringNotContainsString('locations@unite.be', (string) $row['username_encrypted']);
    }

    public function testAMailboxObjectCarriesNoPasswordAtAll(): void
    {
        // Not "is empty" — the property does not exist, so it cannot reach
        // a template, a listing or a rendered stack trace (§7.4).
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);

        $this->assertFalse(property_exists($mailbox, 'password'));
        $this->assertNotContains('password', array_keys(get_object_vars($mailbox)));
    }
}
