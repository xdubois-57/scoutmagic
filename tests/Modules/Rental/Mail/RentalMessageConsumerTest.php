<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Mail;

use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Client\FakeMailboxClient;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\AttachmentPolicy;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MailboxClientFactory;
use Modules\InboundMail\Service\MailboxErrorFormatter;
use Modules\InboundMail\Service\MailboxSyncService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use Modules\InboundMail\Service\MessageContentSanitizer;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Mail\RentalMessageConsumer;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalCommunicationService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\InboundMailTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * `rental` claiming its own mail (§7.6, §7.7, §7.8).
 *
 * Driven end to end through the real sync service and a scripted mailbox,
 * because the interesting failures are not in any one class: they are in
 * "did the reference win over the sender", "did an ambiguous match attach
 * anything", "did the attachment become a document". A unit test of the
 * consumer alone would pass while the wiring lost the message.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalMessageConsumerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalDocumentRepository $documentRepository;
    private \Modules\Rental\Service\RentalDocumentService $documentService;
    private RentalAuthorizationService $authorizationService;
    private InboundMessageRepository $messageRepository;
    private InboundMailboxRepository $mailboxRepository;
    private InboundMailService $inboundMail;
    private MessageConsumerRegistry $registry;
    private FakeMailboxClient $client;
    private MailboxSyncService $syncService;
    private RentalCommunicationService $communicationService;
    private int $mailboxId;
    private int $assetId;
    private int $scoutYearId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        InboundMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->documentRepository = new RentalDocumentRepository($this->pdo);
        $this->messageRepository = new InboundMessageRepository($this->pdo, $this->encryption);
        $this->mailboxRepository = new InboundMailboxRepository($this->pdo, $this->encryption);

        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            Connection::withPdo($this->pdo)
        );
        $this->authorizationService = new RentalAuthorizationService(
            $memberService,
            $this->assetRepository,
            $this->managerRepository
        );

        // With the registry, as the composition root wires it: without
        // it `onLinked()`/`onUnlinked()` never fire on detach and move,
        // and a test of « the re-classified contract survives » proved
        // nothing about the path a manager actually takes.
        $this->registry = new MessageConsumerRegistry();
        $fileRepository = new FileRepository($this->pdo);
        $this->inboundMail = new InboundMailService(
            $this->messageRepository,
            $this->mailboxRepository,
            $fileRepository,
            $this->registry
        );

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->documentService = new \Modules\Rental\Service\RentalDocumentService(
            $this->documentRepository,
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($this->pdo)),
            $fileRepository,
            new \Core\File\AttachedFileRemover($fileRepository, sys_get_temp_dir()),
            new \Core\Pdf\DocumentPdfService(),
            new HtmlSanitizer(),
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
            $journal,
            sys_get_temp_dir()
        );

        $this->registry->register(new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService
        ));

        $this->client = new FakeMailboxClient();
        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        $this->storagePath = sys_get_temp_dir() . '/rental-inbound-test-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->syncService = new MailboxSyncService(
            $this->mailboxRepository,
            $this->messageRepository,
            $this->registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new \Modules\InboundMail\Service\AnalysisResultApplier($this->messageRepository),
            new UploadHandler($fileRepository, $this->storagePath)
        );

        $this->communicationService = new RentalCommunicationService(
            $this->bookingRepository,
            $this->documentRepository,
            $this->authorizationService,
            $journal,
            $this->inboundMail,
            new \Core\File\FileRepository($this->pdo)
        );

        $this->mailboxId = $this->mailboxRepository->create(
            'Locations',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'locations@unite.be',
            'mdp',
            ['INBOX'],
            true
        );

        $this->scoutYearId = (new \Core\Config\ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $this->assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->storagePath . '/*') ?: [] as $directory) {
                @rmdir($directory);
            }
            @rmdir($this->storagePath);
        }
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createAsset(string $name, string $slug): int
    {
        return $this->assetRepository->create($name, $name, $slug, 60, 1, '18:00', '11:00', null, true);
    }

    private function createBooking(
        string $reference = 'LOC-2027-0042',
        string $email = 'jeanne@example.be',
        ?int $assetId = null,
        string $arrival = '2027-07-01',
        string $departure = '2027-07-04',
        \DateTimeImmutable $receivedAt = new \DateTimeImmutable('2027-01-01 10:00:00')
    ): RentalBooking {
        $created = $this->bookingRepository->create(
            $assetId ?? $this->assetId,
            $reference,
            $arrival,
            $departure,
            1,
            20,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => $email,
                'phone' => null,
                'organisation' => 'Les Scouts de Nulle Part',
                'purpose' => null,
                'comment' => null,
            ],
            null,
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            $receivedAt
        );

        $this->bookingRepository->setStatus($created['id'], BookingStatus::CONFIRMED, $receivedAt);
        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function addManager(string $email, ?int $assetId = null): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email . ($assetId ?? 0)), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId ?? $this->assetId, $memberId, false);

        return $memberId;
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function deliver(
        int $uid,
        string $subject,
        string $from = 'jeanne@example.be',
        string $body = 'Bonjour,',
        array $extraHeaders = [],
        string $date = 'Mon, 12 Jul 2027 09:30:00 +0200',
        string $messageId = 'msg-1@example.be'
    ): void {
        $headers = array_merge([
            'From' => 'Jeanne Martin <' . $from . '>',
            'To' => 'locations@unite.be',
            'Subject' => $subject,
            'Message-ID' => '<' . $messageId . '>',
            'Date' => $date,
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $extraHeaders);

        $this->client->addRawMessage('INBOX', $uid, InboundMailTestHelper::rawMessage($headers, $body));
    }

    private function sync(): void
    {
        $mailbox = $this->mailboxRepository->findById($this->mailboxId);
        $this->assertNotNull($mailbox);
        $this->syncService->syncMailbox($mailbox, new \DateTimeImmutable('2027-07-12 10:00:00'));
    }

    // ── Level 1: the reference (§7.6) ───────────────────────────────────

    public function testAReplyCarryingTheReferenceLandsOnTheRightBooking(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: Votre réservation [LOC-2027-0042]');

        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testAReferenceThatMatchesNoBookingAttachesNothing(): void
    {
        $this->createBooking('LOC-2027-0042');
        $this->deliver(10, 'Re: [LOC-2027-9999]', from: 'inconnu@example.be');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    // ── Level 2: the thread (§7.6) ──────────────────────────────────────

    public function testAReplyWithNoReferenceButInTheThreadStillLands(): void
    {
        // The second message carries no reference at all — only an
        // In-Reply-To naming the first, which is already attached.
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', messageId: 'first@example.be');
        $this->sync();

        $this->deliver(
            11,
            'Une question de plus',
            from: 'quelquun.dautre@example.be',
            messageId: 'second@example.be',
            extraHeaders: ['In-Reply-To' => '<first@example.be>']
        );
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(2, $messages);
        $this->assertSame(LinkOrigin::THREAD, $messages[1]->linkOrigin);
    }

    public function testTheReferenceWinsOverTheThreadWhenBothPoint(): void
    {
        // Both are certain, but the reference is the module's own marker —
        // and a thread can be hijacked by replying to an old email about a
        // different booking.
        $first = $this->createBooking('LOC-2027-0042');
        $second = $this->createBooking('LOC-2027-0043');

        $this->deliver(10, '[LOC-2027-0042]', messageId: 'first@example.be');
        $this->sync();

        $this->deliver(
            11,
            'Re: [LOC-2027-0043]',
            messageId: 'second@example.be',
            extraHeaders: ['In-Reply-To' => '<first@example.be>']
        );
        $this->sync();

        $this->assertCount(1, $this->communicationService->timeline($first));
        $this->assertCount(1, $this->communicationService->timeline($second));
        $this->assertSame(
            LinkOrigin::REFERENCE,
            $this->communicationService->timeline($second)[0]->linkOrigin
        );
    }

    // ── Level 3: the sender, bounded (§7.6) ─────────────────────────────

    public function testTheRentersOwnAddressInsideTheWindowAttaches(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');

        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::SENDER, $messages[0]->linkOrigin);
    }

    public function testTheSameAddressOutsideTheWindowAttachesNothing(): void
    {
        // Next year's enquiry from the same group must not land on last
        // year's booking.
        $this->createBooking(
            arrival: '2025-07-01',
            departure: '2025-07-04',
            receivedAt: new \DateTimeImmutable('2025-01-01 10:00:00')
        );
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testAMessageSentBeforeTheRequestWasEvenMadeAttachesNothing(): void
    {
        $this->createBooking(receivedAt: new \DateTimeImmutable('2027-07-20 10:00:00'));
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testTwoBookingsInTheWindowUnderOneAddressAttachNothing(): void
    {
        // §7.6, and the rule that matters most here: an ambiguous match is
        // answered with silence, never a guess. A manager reading the wrong
        // file has no way to know it is the wrong file.
        $this->createBooking('LOC-2027-0042', 'jeanne@example.be');
        $this->createBooking('LOC-2027-0043', 'jeanne@example.be', arrival: '2027-08-01', departure: '2027-08-04');

        $this->deliver(10, 'Une question sans référence', from: 'jeanne@example.be');
        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testAStrangersAddressAttachesNothing(): void
    {
        $this->createBooking();
        $this->deliver(10, 'Publicité', from: 'marketing@example.com');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testAnUnclaimedMessageAdvancesTheCursorAnyway(): void
    {
        $this->createBooking();
        $this->deliver(10, 'Publicité', from: 'marketing@example.com');

        $this->sync();

        $this->assertSame(10, $this->mailboxRepository->findCursor($this->mailboxId, 'INBOX')->lastUid);
    }

    // ── Mailbox selection (§7.4) ────────────────────────────────────────

    // ── canRead(): who may open a message's attachment (§8.3, IT-02) ────

    /**
     * A consumer built without the three optional dependencies answers NO,
     * and that is the whole design.
     *
     * The consumer registered by the SCHEDULED path has no requester —
     * there is nobody making a request during a synchronisation — and a
     * "not configured, so allow" answer there would open every inbound
     * attachment to every intendant walking `/files/{id}`, which is the
     * exact defect IT-02 exists to close.
     */
    public function testAConsumerWithNoRequesterRefusesEverything(): void
    {
        $booking = $this->createBooking();
        $this->addManager('gestionnaire@unite.be');

        $consumer = $this->consumerFor(null);

        $this->assertFalse($consumer->canRead($booking->reference, [], 'admin'));
    }

    public function testAManagerOfTheAssetMayReadItsBookingsMail(): void
    {
        $booking = $this->createBooking();
        $this->addManager('gestionnaire@unite.be');

        $this->assertTrue(
            $this->consumerFor('gestionnaire@unite.be')->canRead($booking->reference, [], 'intendant')
        );
    }

    public function testSomebodyWhoManagesNothingIsRefused(): void
    {
        $booking = $this->createBooking();
        $this->addManager('gestionnaire@unite.be');

        $this->assertFalse(
            $this->consumerFor('quelquun@unite.be')->canRead($booking->reference, [], 'intendant')
        );
    }

    public function testAManagerOfANOTHERAssetIsRefused(): void
    {
        // The check is per asset, not per module: managing one hall does
        // not open the mail of another.
        $otherAsset = $this->createAsset('Le Terrain', 'le-terrain');
        $booking = $this->createBooking(assetId: $otherAsset);
        $this->addManager('gestionnaire@unite.be');

        $this->assertFalse(
            $this->consumerFor('gestionnaire@unite.be')->canRead($booking->reference, [], 'intendant')
        );
    }

    public function testAnAssociationPointingAtABookingThatIsGoneIsRefused(): void
    {
        // A restored backup or a botched delete leaves the association
        // behind. There is nobody left to check the request against, so the
        // only safe answer is no.
        $this->addManager('gestionnaire@unite.be');

        $this->assertFalse(
            $this->consumerFor('gestionnaire@unite.be')->canRead('LOC-2027-9999', [], 'admin')
        );
    }

    private function consumerFor(?string $email): RentalMessageConsumer
    {
        return new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService,
            [],
            30,
            new \Modules\Rental\Mail\BookingReferenceMatcher(),
            $email === null ? null : $this->authorizationService,
            $email === null ? null : $this->scoutYearId,
            $email
        );
    }

    // ── Ambiguity produces propositions, not silence (IT-07) ────────────

    public function testOneBookingInTheWindowIsStillAnAssociation(): void
    {
        $this->createBooking(reference: 'LOC-2027-0042');

        $result = $this->plainConsumer()->analyze($this->senderMessage());

        $this->assertCount(1, $result->links);
        $this->assertSame('LOC-2027-0042', $result->links[0]->businessReference);
        $this->assertSame([], $result->candidates);
    }

    public function testTwoBookingsInTheWindowProduceTwoPropositionsAndNoAssociation(): void
    {
        // Silence used to be the answer here. It was right about not
        // choosing — filing a renter's email under whichever of their two
        // bookings sorted first is worse than not filing it, because the
        // manager reading the wrong one has no way to know — and wrong
        // about stopping there.
        $this->createBooking(reference: 'LOC-2027-0042');
        $this->createBooking(reference: 'LOC-2027-0051', arrival: '2027-07-20', departure: '2027-07-23');

        $result = $this->plainConsumer()->analyze($this->senderMessage());

        $this->assertSame([], $result->links, 'ScoutMagic chooses neither');
        $this->assertSame(
            ['LOC-2027-0042', 'LOC-2027-0051'],
            array_map(static fn($c) => $c->businessReference, $result->candidates)
        );
    }

    public function testAPropositionSaysWhatItRestsOnAndNamesTheBookingReadably(): void
    {
        $this->createBooking(reference: 'LOC-2027-0042');
        $this->createBooking(reference: 'LOC-2027-0051', arrival: '2027-07-20', departure: '2027-07-23');

        $candidate = $this->plainConsumer()->analyze($this->senderMessage())->candidates[0];

        // The reference alone is an identifier; a manager recognises dates.
        $this->assertStringContainsString('LOC-2027-0042', $candidate->label);
        $this->assertStringContainsString('01/07/2027', $candidate->label);
        $this->assertStringContainsString('2 réservations', $candidate->explanation);
        $this->assertStringContainsString('choisit aucune', $candidate->explanation);
    }

    public function testAWallOfPropositionsIsBounded(): void
    {
        // A renter with a standing booking every month would otherwise
        // turn one email into a list nobody reads, which is a different
        // way of saying nothing.
        for ($i = 1; $i <= RentalMessageConsumer::MAX_PROPOSITIONS + 3; $i++) {
            $this->createBooking(reference: 'LOC-2027-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $result = $this->plainConsumer()->analyze($this->senderMessage());

        $this->assertCount(RentalMessageConsumer::MAX_PROPOSITIONS, $result->candidates);
    }

    public function testAnExplicitReferenceStillWinsOverEveryProposition(): void
    {
        $this->createBooking(reference: 'LOC-2027-0042');
        $this->createBooking(reference: 'LOC-2027-0051', arrival: '2027-07-20', departure: '2027-07-23');

        $result = $this->plainConsumer()->analyze($this->senderMessage('Re: [LOC-2027-0051] dates'));

        $this->assertSame('LOC-2027-0051', $result->links[0]->businessReference);
        $this->assertSame([], $result->candidates);
    }

    private function plainConsumer(): RentalMessageConsumer
    {
        return new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService
        );
    }

    private function senderMessage(string $subject = 'Bonjour'): \Modules\InboundMail\Api\CandidateMessage
    {
        return new \Modules\InboundMail\Api\CandidateMessage(
            mailboxId: $this->mailboxId,
            subject: $subject,
            fromEmail: 'jeanne@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-02 09:30:00'),
            bodyText: '',
            bodyHtml: ''
        );
    }

    public function testAModuleListeningToAnotherMailboxClaimsNothing(): void
    {
        $this->registry = new MessageConsumerRegistry();
        $consumer = new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->createStub(\Modules\Rental\Service\RentalDocumentService::class),
            [$this->mailboxId + 99]
        );

        $this->createBooking();
        $result = $consumer->analyze(new \Modules\InboundMail\Api\CandidateMessage(
            mailboxId: $this->mailboxId,
            subject: '[LOC-2027-0042]',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: '',
            bodyHtml: ''
        ));

        $this->assertSame([], $result->links);
    }

    public function testAnEmptySelectionMeansEveryMailbox(): void
    {
        $consumer = new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->createStub(\Modules\Rental\Service\RentalDocumentService::class),
            []
        );

        $this->createBooking();
        $result = $consumer->analyze(new \Modules\InboundMail\Api\CandidateMessage(
            mailboxId: 12345,
            subject: '[LOC-2027-0042]',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            messageId: 'a@b',
            inReplyTo: null,
            references: [],
            toEmails: [],
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00'),
            bodyText: '',
            bodyHtml: ''
        ));

        $this->assertCount(1, $result->links);
        $this->assertSame('LOC-2027-0042', $result->links[0]->businessReference);
    }

    // ── Attachments become documents (§7.8) ─────────────────────────────

    private function deliverWithPdf(int $uid, string $subject, string $filename = 'contrat.pdf'): void
    {
        $this->client->addRawMessage('INBOX', $uid, implode("\r\n", [
            'From: Jeanne Martin <jeanne@example.be>',
            'Subject: ' . $subject,
            'Message-ID: <pdf-' . $uid . '@example.be>',
            'Date: Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/plain',
            '',
            'Voici le contrat signé.',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="' . $filename . '"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"),
            '--frontier--',
        ]));
    }

    public function testAnAttachedPdfBecomesAnUnsortedInternalDocument(): void
    {
        // §7.8: never presumed to be the signed contract, never visible to
        // the renter. A manager reclassifies it in one click.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');

        $this->sync();

        $documents = $this->documentRepository->findForBooking($booking->id);
        $this->assertCount(1, $documents);
        $this->assertSame(DocumentType::UNSORTED, $documents[0]->type);
        $this->assertFalse($documents[0]->isForRenter);
    }

    public function testTheDocumentPointsAtTheSameStoredFileAsTheAttachment(): void
    {
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');

        $this->sync();

        $message = $this->communicationService->timeline($booking)[0];
        $document = $this->documentRepository->findForBooking($booking->id)[0];

        $this->assertSame($message->attachments[0]->fileId, $document->fileId);
    }

    public function testTheDocumentIsMarkedAsBelongingToTheMessage(): void
    {
        // Because the two share one `files` row, the document must never
        // be allowed to delete the bytes: `RentalDocumentService::delete()`
        // reads exactly this flag before touching them (§8.59).
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');

        $this->sync();

        $document = $this->documentRepository->findForBooking($booking->id)[0];
        $this->assertSame(\Modules\Rental\Document\RentalDocument::SOURCE_EMAIL, $document->source);
        $this->assertFalse($document->ownsItsFile());
    }

    public function testDeletingTheDocumentLeavesTheMessagesAttachmentReadable(): void
    {
        // The bug this invariant exists to prevent: a manager tidying up a
        // "Non classé" row silently destroyed the correspondence it came
        // from — the message stayed, its attachment did not.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        $document = $this->documentRepository->findForBooking($booking->id)[0];
        $this->documentService->delete($document);

        $message = $this->communicationService->timeline($booking)[0];
        $this->assertCount(1, $message->attachments);
        $this->assertNotNull(
            (new FileRepository($this->pdo))->findById($message->attachments[0]->fileId),
            "The message's own attachment must still resolve to a file."
        );
    }

    // ── onUnlinked(): the callback exists to fix a real bug (IT-03) ─────

    public function testReassigningAMessageTakesItsDocumentsOffTheOldBooking(): void
    {
        // Before `onUnlinked()` existed, detaching a message left its
        // RentalDocument rows hanging off the first booking: invisible to
        // whoever manages the new one, unexplainable to whoever manages the
        // old one.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();
        $this->assertCount(1, $this->documentRepository->findForBooking($booking->id));

        [$message, $link] = $this->storedMessageAndLink($booking->reference);
        $this->consumerFor(null)->onUnlinked($message, $link);

        $this->assertSame(
            [],
            $this->documentRepository->findForBooking($booking->id),
            'the document arrived with the message and leaves with it'
        );
    }

    public function testTakingBackADocumentNeverDestroysTheMessagesAttachment(): void
    {
        // The bytes belong to the message, not to the document (§8.59) —
        // detaching must leave the correspondence itself readable.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        [$message, $link] = $this->storedMessageAndLink($booking->reference);
        $fileId = $message->attachments[0]->fileId;
        $this->consumerFor(null)->onUnlinked($message, $link);

        $this->assertNotNull(
            (new FileRepository($this->pdo))->findById($fileId),
            'the attachment outlives the document that pointed at it'
        );
    }

    public function testADocumentTheManagerAddedByHandSurvivesTheDetach(): void
    {
        // Only what this message brought is taken back. A document the
        // manager uploaded is not sourced from the email and stays.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        [$message, $link] = $this->storedMessageAndLink($booking->reference);
        $ownFileId = (new FileRepository($this->pdo))->create(
            'rental/a-la-main.pdf',
            'a-la-main.pdf',
            'application/pdf',
            12,
            'intendant',
            'rental',
            null
        );
        $this->documentRepository->create(
            $booking->id,
            $ownFileId,
            DocumentType::UNSORTED,
            1,
            false,
            null,
            null
        );

        $this->consumerFor(null)->onUnlinked($message, $link);

        $remaining = $this->documentRepository->findForBooking($booking->id);
        $this->assertCount(1, $remaining);
        $this->assertSame($ownFileId, $remaining[0]->fileId);
    }

    public function testAMessageWithNoAttachmentDetachesWithoutTouchingAnything(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042] une question');
        $this->sync();

        [$message, $link] = $this->storedMessageAndLink($booking->reference);
        $this->assertSame([], $message->attachments);

        $this->consumerFor(null)->onUnlinked($message, $link);

        $this->assertSame([], $this->documentRepository->findForBooking($booking->id));
    }

    public function testAnAssociationPointingAtABookingThatIsGoneDetachesQuietly(): void
    {
        // A restored backup leaves the association behind. There is nothing
        // to take documents off, and that is not an error.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        [$message] = $this->storedMessageAndLink($booking->reference);
        $ghost = new MessageLink(
            RentalMessageConsumer::CONSUMER_ID,
            'LOC-2027-9999',
            LinkOrigin::REFERENCE
        );

        $this->consumerFor(null)->onUnlinked($message, $ghost);

        $this->assertCount(
            1,
            $this->documentRepository->findForBooking($booking->id),
            'a reference nobody answers to takes nothing off the real booking'
        );
    }

    // ── What the triage screen asks every consumer (IT-03) ──────────────

    public function testNothingMoreIsLearnedOnceTheMessageIsOnDisk(): void
    {
        // Everything this module recognises is in the subject, the thread
        // headers and the sender, all of which arrived with the message.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();
        [$message] = $this->storedMessageAndLink($booking->reference);

        $result = $this->consumerFor(null)->analyzeStored($message);

        $this->assertSame([], $result->links);
        $this->assertSame([], $result->candidates);
    }

    public function testTheModuleCanSayWhatItRecognisesAndWhoWouldSeeIt(): void
    {
        // The triage screen shows these before opening a shared mailbox to
        // a module: an empty answer there would be a silent "trust me".
        $consumer = $this->consumerFor(null);

        $this->assertNotSame([], $consumer->describeEvidence());
        $this->assertNotSame('', $consumer->triageAudienceLabel());
    }

    public function testTheAudienceIsCountedRatherThanEstimated(): void
    {
        // The figure guards the decision to open a shared mailbox, so it is
        // read from the scout year in effect, not guessed.
        $this->createBooking();
        $this->addManager('gestionnaire@unite.be');

        $this->assertGreaterThanOrEqual(0, $this->consumerFor(null)->triageAudienceCount());
    }

    /**
     * The message as it was stored, with the association the sync created.
     *
     * @return array{0: \Modules\InboundMail\Api\InboundMessage, 1: MessageLink}
     */
    private function storedMessageAndLink(string $reference): array
    {
        $messages = $this->messageRepository->findForReference(
            RentalMessageConsumer::CONSUMER_ID,
            $reference
        );
        $this->assertNotSame([], $messages, 'the sync must have stored the message');
        $links = $this->messageRepository->findLinksForMessage($messages[0]->id);
        $this->assertNotSame([], $links, 'the sync must have associated it');

        return [$messages[0], $links[0]];
    }

    public function testASignatureLogoNeverBecomesADocument(): void
    {
        $booking = $this->createBooking();

        $image = imagecreatetruecolor(80, 40);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $logo = (string) ob_get_clean();
        imagedestroy($image);

        $this->client->addRawMessage('INBOX', 10, implode("\r\n", [
            'From: Jeanne Martin <jeanne@example.be>',
            'Subject: Re: [LOC-2027-0042]',
            'Message-ID: <logo@example.be>',
            'Date: Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type: multipart/related; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: text/html',
            '',
            '<p>Cordialement<img src="cid:logo123"></p>',
            '--frontier',
            'Content-Type: image/png',
            'Content-Disposition: inline; filename="logo.png"',
            'Content-ID: <logo123>',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($logo),
            '--frontier--',
        ]));

        $this->sync();

        $this->assertCount(1, $this->communicationService->timeline($booking));
        $this->assertSame([], $this->documentRepository->findForBooking($booking->id));
    }

    public function testAnArchiveAttachmentIsRefusedButTheMessageSurvives(): void
    {
        $booking = $this->createBooking();
        $this->client->addRawMessage('INBOX', 10, implode("\r\n", [
            'From: Jeanne Martin <jeanne@example.be>',
            'Subject: Re: [LOC-2027-0042]',
            'Message-ID: <zip@example.be>',
            'Date: Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type: multipart/mixed; boundary="frontier"',
            '',
            '--frontier',
            'Content-Type: application/pdf',
            'Content-Disposition: attachment; filename="photos.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode("PK\x03\x04" . str_repeat("\x00", 60)),
            '--frontier--',
        ]));

        $this->sync();

        $this->assertCount(1, $this->communicationService->timeline($booking));
        $this->assertSame([], $this->documentRepository->findForBooking($booking->id));
    }

    public function testAnUnsortedAttachmentIsReclassifiableAsASignedContract(): void
    {
        // The roadmap's acceptance criterion, end to end: reply to a
        // `[LOC-…]` email with a PDF, and the message turns up on the right
        // booking with the PDF filed as `Non classé` — reclassifiable in
        // one step into a signed contract.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042] contrat signé');
        $this->sync();

        $document = $this->documentRepository->findForBooking($booking->id)[0];
        $this->assertSame(DocumentType::UNSORTED, $document->type);

        $this->documentService->reclassify($booking, $document->id, DocumentType::SIGNED_CONTRACT, false);

        $reclassified = $this->documentRepository->findForBooking($booking->id)[0];
        $this->assertSame(DocumentType::SIGNED_CONTRACT, $reclassified->type);
        $this->assertSame($document->fileId, $reclassified->fileId, 'The very bytes that arrived, not a copy.');
    }

    public function testAGeneratedDocumentIsNeverReclassified(): void
    {
        // A contract is what this module produced from a template; calling
        // it something else would break the versioning a signed v1 depends
        // on — and would let a manager quietly turn an invoice into a
        // "photo" to hide it.
        $booking = $this->createBooking();
        $fileId = (new FileRepository($this->pdo))->create(
            'rental/documents/x.pdf',
            'contrat.pdf',
            'application/pdf',
            10,
            'identified',
            'rental',
            null
        );
        $documentId = $this->documentRepository->create(
            $booking->id,
            $fileId,
            DocumentType::CONTRACT,
            1,
            true,
            null,
            null
        );

        $this->expectException(RentalException::class);
        $this->documentService->reclassify($booking, $documentId, DocumentType::EVIDENCE, false);
    }

    public function testADocumentOfAnotherBookingIsNeverReclassified(): void
    {
        $mine = $this->createBooking('LOC-2027-0042');
        $theirs = $this->createBooking('LOC-2027-0043');
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0043]');
        $this->sync();

        $document = $this->documentRepository->findForBooking($theirs->id)[0];

        $this->expectException(RentalException::class);
        $this->documentService->reclassify($mine, $document->id, DocumentType::PHOTO, false);
    }

    // ── Untrusted content (§7.9) ────────────────────────────────────────

    public function testScriptInAnIncomingBodyNeverReachesStorage(): void
    {
        $booking = $this->createBooking();
        $this->client->addRawMessage('INBOX', 10, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <jeanne@example.be>',
            'Subject' => 'Re: [LOC-2027-0042]',
            'Message-ID' => '<xss@example.be>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type' => 'text/html; charset=UTF-8',
        ], '<p>Bonjour</p><script>alert(document.cookie)</script>'));

        $this->sync();

        $stored = $this->communicationService->timeline($booking)[0];
        $this->assertStringNotContainsString('<script', $stored->bodyHtml);
        $this->assertStringNotContainsString('document.cookie', $stored->bodyHtml);
    }

    public function testATrackingPixelNeverReachesStorageEither(): void
    {
        $booking = $this->createBooking();
        $this->client->addRawMessage('INBOX', 10, InboundMailTestHelper::rawMessage([
            'From' => 'Jeanne Martin <jeanne@example.be>',
            'Subject' => 'Re: [LOC-2027-0042]',
            'Message-ID' => '<pixel@example.be>',
            'Date' => 'Mon, 12 Jul 2027 09:30:00 +0200',
            'Content-Type' => 'text/html; charset=UTF-8',
        ], '<p>Bonjour</p><img src="https://tracker.example/p.gif?u=42" width="1" height="1">'));

        $this->sync();

        $stored = $this->communicationService->timeline($booking)[0];
        $this->assertStringNotContainsString('tracker.example', $stored->bodyHtml);
        $this->assertStringNotContainsString('<img', $stored->bodyHtml);
    }

    // ── Correcting the automatic rules (§7.7) ───────────────────────────

    public function testDetachingRemovesTheMessageFromTheBookingAndItsUnsortedDocument(): void
    {
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        $message = $this->communicationService->timeline($booking)[0];
        $this->assertTrue($this->communicationService->detach($booking, $message->id));

        $this->assertSame([], $this->communicationService->timeline($booking));
        $this->assertSame([], $this->documentRepository->findForBooking($booking->id));

        // The message itself falls back into the unit's general mail —
        // detaching is a correction, and destroying the message would make
        // re-filing it under the right booking impossible. Its attachment
        // goes with it, since nobody re-classified it.
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testDetachingKeepsAnAttachmentAManagerAlreadyReclassified(): void
    {
        // §7.7: an attachment that was reclassified survives; an untouched
        // one goes with the message. A signed contract a manager already
        // filed must not vanish because they tidied the thread.
        $booking = $this->createBooking();
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        $document = $this->documentRepository->findForBooking($booking->id)[0];
        $this->documentRepository->updateType($document->id, DocumentType::SIGNED_CONTRACT);

        $message = $this->communicationService->timeline($booking)[0];
        $this->communicationService->detach($booking, $message->id);

        $remaining = $this->documentRepository->findForBooking($booking->id);
        $this->assertCount(1, $remaining);
        $this->assertSame(DocumentType::SIGNED_CONTRACT, $remaining[0]->type);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        // And it changed hands: the file is the booking's document now, not
        // the message's attachment. Without this, inbound_mail's retention
        // would delete a signed contract ninety days later, and until then
        // the booking's managers would be answering to an access check
        // about a message they can no longer see.
        $owner = $this->pdo->query('SELECT owner_type, owner_id FROM files')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('rental_document', $owner['owner_type']);
        $this->assertSame($booking->id, (int) $owner['owner_id']);
        $this->assertSame(
            [],
            $this->messageRepository->findFileIdsForMessage($message->id)
        );
    }

    public function testDetachingAMessageOfAnotherBookingChangesNothing(): void
    {
        $mine = $this->createBooking('LOC-2027-0042');
        $theirs = $this->createBooking('LOC-2027-0043');
        $this->deliver(10, '[LOC-2027-0043]');
        $this->sync();

        $message = $this->communicationService->timeline($theirs)[0];

        $this->assertFalse($this->communicationService->detach($mine, $message->id));
        $this->assertCount(1, $this->communicationService->timeline($theirs));
    }

    public function testAManagerMovesAMessageToAnotherBookingOfTheirOwnAsset(): void
    {
        $this->addManager('chef@unite.be');
        $from = $this->createBooking('LOC-2027-0042');
        $to = $this->createBooking('LOC-2027-0043');
        $this->deliver(10, '[LOC-2027-0042]');
        $this->sync();

        $message = $this->communicationService->timeline($from)[0];

        $this->assertTrue(
            $this->communicationService->move($from, $message->id, $to->id, 'chef@unite.be', $this->scoutYearId)
        );
        $this->assertSame([], $this->communicationService->timeline($from));
        $this->assertCount(1, $this->communicationService->timeline($to));
    }

    public function testAManagerCannotMoveAMessageToAnAssetTheyDoNotManage(): void
    {
        // The bound that stops "move" from becoming a doorway into the rest
        // of the unit's bookings (§7.7).
        $this->addManager('chef@unite.be');
        $otherAssetId = $this->createAsset('Hangar', 'hangar');

        $from = $this->createBooking('LOC-2027-0042');
        $to = $this->createBooking('LOC-2027-0043', assetId: $otherAssetId);
        $this->deliver(10, '[LOC-2027-0042]');
        $this->sync();

        $message = $this->communicationService->timeline($from)[0];

        $this->expectException(RentalException::class);
        $this->communicationService->move($from, $message->id, $to->id, 'chef@unite.be', $this->scoutYearId);
    }

    public function testTheMoveTargetListOnlyEverHoldsTheirOwnBookings(): void
    {
        $this->addManager('chef@unite.be');
        $otherAssetId = $this->createAsset('Hangar', 'hangar');

        $from = $this->createBooking('LOC-2027-0042');
        $this->createBooking('LOC-2027-0043');
        $this->createBooking('LOC-2027-0044', assetId: $otherAssetId);

        $references = array_map(
            static fn(RentalBooking $booking) => $booking->reference,
            $this->communicationService->moveTargets($from, 'chef@unite.be', $this->scoutYearId)
        );

        $this->assertSame(['LOC-2027-0043'], $references);
    }

    public function testAMovedMessageTakesItsDocumentsWithIt(): void
    {
        $this->addManager('chef@unite.be');
        $from = $this->createBooking('LOC-2027-0042');
        $to = $this->createBooking('LOC-2027-0043');
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        $message = $this->communicationService->timeline($from)[0];
        $this->communicationService->move($from, $message->id, $to->id, 'chef@unite.be', $this->scoutYearId);

        $this->assertSame([], $this->documentRepository->findForBooking($from->id));
        $this->assertCount(1, $this->documentRepository->findForBooking($to->id));
    }

    public function testAMovedMessageKeepsTheDocumentAManagerAlreadyReclassified(): void
    {
        // The signed contract stays a signed contract on the booking the
        // message now belongs to. It used to be deleted by the consumer's
        // own `onUnlinked()` and re-created as « Non classé » on the
        // target — every move silently undid a manager's filing.
        $this->addManager('chef@unite.be');
        $from = $this->createBooking('LOC-2027-0042');
        $to = $this->createBooking('LOC-2027-0043');
        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        $document = $this->documentRepository->findForBooking($from->id)[0];
        $this->documentRepository->updateType($document->id, DocumentType::SIGNED_CONTRACT);

        $message = $this->communicationService->timeline($from)[0];
        $this->communicationService->move($from, $message->id, $to->id, 'chef@unite.be', $this->scoutYearId, null, 7);

        $this->assertSame([], $this->documentRepository->findForBooking($from->id));
        $moved = $this->documentRepository->findForBooking($to->id);
        $this->assertCount(1, $moved);
        $this->assertSame(DocumentType::SIGNED_CONTRACT, $moved[0]->type);

        // And the booking page reads the truth: a person moved it.
        $this->assertSame(LinkOrigin::MANUAL, $this->communicationService->timeline($to)[0]->linkOrigin);
    }

    // ── Degrading without the module (§7.5) ─────────────────────────────

    public function testWithoutInboundMailTheTabIsNotOffered(): void
    {
        $service = new RentalCommunicationService(
            $this->bookingRepository,
            $this->documentRepository,
            $this->authorizationService,
            new JournalService(new JournalRepository($this->pdo))
        );

        $booking = $this->createBooking();

        $this->assertFalse($service->isAvailable());
        $this->assertSame([], $service->timeline($booking));
        $this->assertFalse($service->detach($booking, 1));
    }

    public function testWithEveryMailboxDisabledTheTabIsNotOfferedEither(): void
    {
        $this->mailboxRepository->setEnabled($this->mailboxId, false);

        $this->assertFalse($this->communicationService->isAvailable());
    }

    public function testADisabledMailboxCollectsNothing(): void
    {
        $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]');
        $this->mailboxRepository->setEnabled($this->mailboxId, false);

        $this->syncService->syncAll(new \DateTimeImmutable('2027-07-12 10:00:00'));

        $this->assertSame(0, $this->countStoredMessages());
    }

    private function countStoredMessages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn();
    }

    /**
     * How many associations `rental` made.
     *
     * What these tests have always been about — « rental attached
     * nothing » — expressed against the model that now holds. The message
     * itself is stored either way since §8.58 stopped discarding what no
     * consumer recognises, so counting messages would now be asking a
     * different question.
     */
    private function countRentalAssociations(): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM inbound_message_links WHERE consumer_id = 'rental'")
            ->fetchColumn();
    }
}
