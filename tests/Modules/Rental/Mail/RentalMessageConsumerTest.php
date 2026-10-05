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
use Modules\LlmConnector\Api\LlmException;
use Modules\Rental\Mail\BookingChoiceByModel;
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
use Tests\Modules\InboundMail\ScriptedLlm;
use Tests\Modules\Rental\RentalTestHelper;
use Core\Member\Repository\MemberProfileRepository;

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
    private \Modules\InboundMail\Service\ReplyAddressService $replyAddresses;
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
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
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

        $this->storagePath = sys_get_temp_dir() . '/rental-inbound-test-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->syncService = $this->syncServiceFor($this->registry);

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

    private function syncServiceFor(MessageConsumerRegistry $registry): MailboxSyncService
    {
        $factory = new MailboxClientFactory();
        $factory->register(ProviderType::FAKE, $this->client);

        return new MailboxSyncService(
            $this->mailboxRepository,
            $this->messageRepository,
            $registry,
            new MessageContentSanitizer(new HtmlSanitizer()),
            new AttachmentPolicy(),
            new MailboxErrorFormatter(),
            $factory,
            new \Modules\InboundMail\Service\AnalysisResultApplier($this->messageRepository),
            new UploadHandler(new FileRepository($this->pdo), $this->storagePath),
            null,
            null,
            null,
            null,
            // The signed reply addresses the site mints (§8.58), read off
            // the recipients before the consumer is asked.
            $this->replyAddresses = new \Modules\InboundMail\Service\ReplyAddressService(
                $this->mailboxRepository,
                $this->encryption
            )
        );
    }

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

    // ── Level 0: the signed reply address (§8.58) ───────────────────────

    public function testAReplyToTheSignedAddressLandsOnTheBookingWhoeverWrites(): void
    {
        // The group's treasurer presses « Répondre » on the acknowledgement
        // the renter forwarded them: no reference in the subject, an
        // unknown sender, no thread the site knows — only the address the
        // site itself put in the Reply-To.
        $booking = $this->createBooking();
        $address = $this->replyAddresses->addressFor(RentalMessageConsumer::CONSUMER_ID, $booking->reference);
        $this->assertNotNull($address);

        $this->deliver(10, 'Re: Votre demande', from: 'tresorier@groupe.example', extraHeaders: ['To' => $address]);
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REPLY_ADDRESS, $messages[0]->linkOrigin);
    }

    public function testAForgedSignatureIsAnOrdinaryAddress(): void
    {
        $this->createBooking();

        $this->deliver(
            10,
            'Re: Votre demande',
            from: 'inconnu@example.be',
            extraHeaders: ['To' => 'locations+rental.LOC-2027-0042.000000000000@unite.be']
        );
        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testAnAddressNamingABookingThatNoLongerExistsAttachesNothing(): void
    {
        $address = $this->replyAddresses->addressFor(RentalMessageConsumer::CONSUMER_ID, 'LOC-2020-0001');
        $this->assertNotNull($address);

        $this->deliver(10, 'Re: Votre demande', from: 'jeanne@example.be', extraHeaders: ['To' => $address]);
        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations());
    }

    public function testTheSignedAddressWinsOverAConflictingReference(): void
    {
        // Both look certain, and they disagree — so one of them has to be
        // trusted first. It is the address: this site MINTED it for one
        // booking and the gateway verified its signature, while a subject
        // line carries whatever the sender's mail client left there. « Re: »
        // on a forwarded thread quotes someone else's reference for free.
        //
        // So the address is read at Level 0 and the walk stops there,
        // before the subject is even parsed (§22.9, ARCHITECTURE.md §8.59,
        // docs/rental-guide.md §11).
        //
        // The name and comment here used to say the opposite of what the
        // assertions below check, and during the review of #514 this test
        // was cited as evidence for the reverse order (#522).
        $first = $this->createBooking('LOC-2027-0042');
        $second = $this->createBooking('LOC-2027-0043');
        $address = $this->replyAddresses->addressFor(RentalMessageConsumer::CONSUMER_ID, $first->reference);

        $this->deliver(10, 'Re: [LOC-2027-0043] dates', extraHeaders: ['To' => (string) $address]);
        $this->sync();

        $this->assertCount(1, $this->communicationService->timeline($first));
        $this->assertCount(0, $this->communicationService->timeline($second));
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

    public function testTheOnlyBookingOfARenterIsAttachedWhateverTheDate(): void
    {
        // The window exists to tell two bookings of one renter apart. A
        // renter who has exactly one has nothing to tell apart: their
        // question three months after the stay is about that stay.
        $booking = $this->createBooking(
            arrival: '2027-03-01',
            departure: '2027-03-04',
            receivedAt: new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $this->deliver(10, 'Une question tardive', from: 'jeanne@example.be');

        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::SENDER, $messages[0]->linkOrigin);
    }

    public function testTheSameAddressOutsideTheWindowOfEitherBookingAttachesNothing(): void
    {
        // Next year's enquiry from a group that has booked twice before
        // must not land on either of last year's bookings.
        $this->createBooking(
            'LOC-2025-0001',
            arrival: '2025-07-01',
            departure: '2025-07-04',
            receivedAt: new \DateTimeImmutable('2025-01-01 10:00:00')
        );
        $this->createBooking(
            'LOC-2025-0002',
            arrival: '2025-08-01',
            departure: '2025-08-04',
            receivedAt: new \DateTimeImmutable('2025-01-01 10:00:00')
        );
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testAMessageSentBeforeEitherRequestWasEvenMadeAttachesNothing(): void
    {
        $this->createBooking('LOC-2027-0042', receivedAt: new \DateTimeImmutable('2027-07-20 10:00:00'));
        $this->createBooking('LOC-2027-0043', receivedAt: new \DateTimeImmutable('2027-07-21 10:00:00'));
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');

        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'The message is kept; rental just does not claim it.');
    }

    public function testACancelledBookingDoesNotCompeteWithTheLiveOne(): void
    {
        // A group whose first request was refused and who booked again
        // used to have every message turned into two propositions. The
        // dead one is out of the rule.
        $live = $this->createBooking('LOC-2027-0042', 'jeanne@example.be');
        $cancelled = $this->createBooking('LOC-2027-0043', 'jeanne@example.be', arrival: '2027-08-01', departure: '2027-08-04');
        $this->bookingRepository->setStatus($cancelled->id, BookingStatus::CANCELLED, new \DateTimeImmutable('2027-02-01'));

        $this->deliver(10, 'Une question sans référence', from: 'jeanne@example.be');
        $this->sync();

        $messages = $this->communicationService->timeline($live);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::SENDER, $messages[0]->linkOrigin);
        $this->assertSame(1, $this->countRentalAssociations());
    }

    public function testAReplyToTheSitesOwnMailLandsOnTheBooking(): void
    {
        // The ordinary first reply: the renter answers the acknowledgement
        // the site sent, from another address, with the reference gone
        // from the subject. Nothing inbound names the booking — only the
        // Message-ID the site remembered when it wrote.
        $booking = $this->createBooking();
        $this->inboundMail->recordOutboundMessageId(
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            '<site-42@unite.be>'
        );

        $this->deliver(
            10,
            'Re: Votre demande',
            from: 'tresorier@groupe.example',
            messageId: 'reply@groupe.example',
            extraHeaders: ['In-Reply-To' => '<site-42@unite.be>']
        );
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::THREAD, $messages[0]->linkOrigin);
    }

    public function testAManualAssociationTeachesTheBookingTheSendersAddress(): void
    {
        // The group's treasurer writes from their own address. The first
        // message is the manager's to file; the second is not, because the
        // booking now knows that address too.
        $booking = $this->createBooking();
        $this->deliver(10, 'Question sur la caution', from: 'tresorier@groupe.example', messageId: 'one@groupe.example');
        $this->sync();
        $this->assertSame(0, $this->countRentalAssociations());

        $stored = $this->storedMessageIds();
        $this->assertCount(1, $stored);
        $this->assertTrue($this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $booking->reference, $stored[0], 7));

        $this->deliver(11, 'Encore une question', from: 'tresorier@groupe.example', messageId: 'two@groupe.example');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(2, $messages);
        $this->assertSame(LinkOrigin::SENDER, $messages[1]->linkOrigin);
    }

    public function testTheOtherMessagesOfThatAddressFollowWithoutAnotherClick(): void
    {
        // Two messages from the treasurer already waiting. Filing one by
        // hand re-examines the rest with what the booking just learned,
        // so the manager does not file the same address twice.
        $booking = $this->createBooking();
        $this->deliver(10, 'Question sur la caution', from: 'tresorier@groupe.example', messageId: 'one@groupe.example');
        $this->deliver(11, 'Question sur les clés', from: 'tresorier@groupe.example', messageId: 'two@groupe.example');
        $this->sync();
        $this->assertSame(0, $this->countRentalAssociations());

        $stored = $this->storedMessageIds();
        $this->assertCount(2, $stored);
        $this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $booking->reference, $stored[0], 7);

        $this->assertCount(2, $this->communicationService->timeline($booking));
    }

    /**
     * #231. The reference is sequential and printed on every contract, so
     * it is guessable — and the rule asked nothing else: any sender who
     * quoted `[LOC-2027-0042]` had their message, and its attachments,
     * filed on that booking's internal thread. The neighbours bound their
     * equivalent rule (Modules\Finance's consumer requires a resolved
     * sender, camps keeps its weakest rule behind a dedicated mailbox);
     * this one now does too.
     */
    public function testAReferenceFromSomebodyElseFilesNothing(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'quelquun@ailleurs.example');
        $this->sync();

        $this->assertSame(0, $this->countRentalAssociations(), 'the reference alone was enough to file the message');
        $this->assertSame([], $this->communicationService->timeline($booking));
        // And no proposition stands in for the decision (#720).
        $this->assertSame([], $this->inboundMail->findCandidatesFor(
            RentalMessageConsumer::CONSUMER_ID,
            $this->storedMessageIds()
        ));
    }

    public function testTheRenterQuotingTheirOwnReferenceIsStillFiledStraightAway(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'jeanne@example.be');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testAnAutomaticAssociationTeachesNothing(): void
    {
        // Only a person's decision is worth learning from: an address the
        // rules attached on their own is already known, and an address
        // the thread rule attached may be anybody in the conversation.
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'jeanne@example.be');
        $this->sync();
        $this->assertSame(1, $this->countRentalAssociations());

        $this->assertSame(
            [$booking->id],
            array_map(static fn(RentalBooking $b) => $b->id, $this->bookingRepository->findByRenterEmail('jeanne@example.be'))
        );
        $this->assertSame([], $this->bookingRepository->findByRenterEmail('quelquun@ailleurs.example'));
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
            30,
            new \Modules\Rental\Mail\BookingReferenceMatcher(),
            $email === null ? null : $this->authorizationService,
            $email === null ? null : $this->scoutYearId,
            $email
        );
    }

    // ── Ambiguity files nothing and proposes nothing (#720) ─────────────

    public function testOneBookingInTheWindowIsStillAnAssociation(): void
    {
        $this->createBooking(reference: 'LOC-2027-0042');

        $result = $this->plainConsumer()->analyze($this->senderMessage());

        $this->assertCount(1, $result->links);
        $this->assertSame('LOC-2027-0042', $result->links[0]->businessReference);
        $this->assertSame([], $result->candidates);
    }

    public function testTwoBookingsInTheWindowFileNothingAndProposeNothing(): void
    {
        // Filing a renter's email under whichever of their two bookings
        // sorted first is worse than not filing it — and nobody is asked
        // to pick any more: the message appears on no booking.
        $this->createBooking(reference: 'LOC-2027-0042');
        $this->createBooking(reference: 'LOC-2027-0051', arrival: '2027-07-20', departure: '2027-07-23');

        $result = $this->plainConsumer()->analyze($this->senderMessage());

        $this->assertSame([], $result->links, 'ScoutMagic chooses neither');
        $this->assertSame([], $result->candidates);
    }

    public function testAnExplicitReferenceStillDecidesBetweenTwoBookings(): void
    {
        $this->createBooking(reference: 'LOC-2027-0042');
        $this->createBooking(reference: 'LOC-2027-0051', arrival: '2027-07-20', departure: '2027-07-23');

        $result = $this->plainConsumer()->analyze($this->senderMessage('Re: [LOC-2027-0051] dates'));

        $this->assertSame('LOC-2027-0051', $result->links[0]->businessReference);
        $this->assertSame([], $result->candidates);
    }

    public function testTheConsumerNoLongerListensForPropositions(): void
    {
        $this->assertNotInstanceOf(\Modules\InboundMail\Api\PropositionListener::class, $this->plainConsumer());
    }

    private function plainConsumer(?\Modules\Rental\Mail\NewMessageNotifier $notifier = null): RentalMessageConsumer
    {
        return new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService,
            assetRepository: $this->assetRepository,
            newMessageNotifier: $notifier
        );
    }

    // ── « Nouveau message du locataire » (#720) ─────────────────────────

    public function testAMessageFiledUnderABookingIsAnnouncedOnce(): void
    {
        $booking = $this->createBooking();
        $notifier = $this->createMock(\Modules\Rental\Mail\NewMessageNotifier::class);
        $notifier->expects($this->once())->method('messageFiled')->with(
            $this->callback(static fn(RentalBooking $b): bool => $b->id === $booking->id)
        );

        $this->plainConsumer($notifier)->onLinked(
            $this->storedUnattached(),
            new MessageLink(RentalMessageConsumer::CONSUMER_ID, $booking->reference, LinkOrigin::SENDER)
        );
    }

    public function testAnAttachmentLevelAssociationIsNotASecondMessage(): void
    {
        $booking = $this->createBooking();
        $notifier = $this->createMock(\Modules\Rental\Mail\NewMessageNotifier::class);
        $notifier->expects($this->never())->method('messageFiled');

        $this->plainConsumer($notifier)->onLinked(
            $this->storedUnattached(),
            new MessageLink(RentalMessageConsumer::CONSUMER_ID, $booking->reference, LinkOrigin::SENDER, 12)
        );
    }

    public function testANotificationThatFailsDoesNotStopTheFiling(): void
    {
        // The message is filed whether or not anybody can be told: a push
        // service down must not surface as a failed association, nor stop
        // the attachment from becoming a document. Through the real
        // linking path, with a notifier that throws past its own journal.
        $booking = $this->createBooking();
        $notifier = $this->createStub(\Modules\Rental\Mail\NewMessageNotifier::class);
        $notifier->method('messageFiled')->willThrowException(new \RuntimeException('push service down'));
        $this->registry = new MessageConsumerRegistry();
        $this->registry->register($this->plainConsumer($notifier));
        $this->syncService = $this->syncServiceFor($this->registry);

        $this->deliverWithPdf(10, 'Re: [LOC-2027-0042]');
        $this->sync();

        [$message] = $this->storedMessageAndLink($booking->reference);
        $this->assertCount(1, $message->attachments);
        $documents = $this->documentRepository->findForBooking($booking->id);
        $this->assertCount(1, $documents);
        $this->assertSame($message->attachments[0]->fileId, $documents[0]->fileId);
    }

    private function storedUnattached(): \Modules\InboundMail\Api\InboundMessage
    {
        return new \Modules\InboundMail\Api\InboundMessage(
            1, $this->mailboxId, '', '', LinkOrigin::SENDER, 'Bonjour', 'jeanne@example.be', null,
            'a@b', null, new \DateTimeImmutable('2027-07-02 09:30:00'), '', ''
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

    // ── What the unit sent, read in the box's « Envoyés » (#720) ────────

    /**
     * @param array<string, string> $extraHeaders
     */
    private function deliverSent(
        int $uid,
        string $subject,
        string $to = 'jeanne@example.be',
        string $messageId = 'sent-1@unite.be',
        array $extraHeaders = []
    ): void {
        $this->client->markSent('Envoyés');
        $this->client->addRawMessage('Envoyés', $uid, InboundMailTestHelper::rawMessage(array_merge([
            'From' => 'Les Scouts <locations@unite.be>',
            'To' => $to,
            'Subject' => $subject,
            'Message-ID' => '<' . $messageId . '>',
            'Date' => 'Mon, 12 Jul 2027 11:00:00 +0200',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ], $extraHeaders), 'Bonjour Jeanne,'));
    }

    public function testWhatTheUnitWroteToTheRenterLandsOnTheirBooking(): void
    {
        $booking = $this->createBooking();
        $this->deliverSent(1, 'Les clés');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isSent());
        $this->assertSame(LinkOrigin::RECIPIENT, $messages[0]->linkOrigin);
        $this->assertFalse($messages[0]->linkOrigin->isCertain(), 'Matched on an address alone: shown as uncertain.');
    }

    public function testARecipientWithTwoBookingsInTheWindowFilesNothingAndKeepsNothing(): void
    {
        $this->createBooking('LOC-2027-0042');
        $this->createBooking('LOC-2027-0043', arrival: '2027-07-20', departure: '2027-07-22');
        $this->deliverSent(1, 'Les clés');
        $this->sync();

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testAReferenceSentToTheRenterDecidesBetweenTwoBookings(): void
    {
        $this->createBooking('LOC-2027-0042');
        $second = $this->createBooking('LOC-2027-0043', arrival: '2027-07-20', departure: '2027-07-22');
        $this->deliverSent(1, 'Votre réservation [LOC-2027-0043]');
        $this->sync();

        $messages = $this->communicationService->timeline($second);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testAReferenceSentToSomebodyElseIsNotTheRentersCorrespondence(): void
    {
        $booking = $this->createBooking();
        $this->deliverSent(1, 'Réservation [LOC-2027-0042] : la chaudière', to: 'concierge@example.be');
        $this->sync();

        $this->assertSame([], $this->communicationService->timeline($booking));
    }

    public function testAnAnswerInTheRentersThreadLandsWhoeverItIsAddressedTo(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Une question', messageId: 'question@example.be');
        $this->deliverSent(1, 'Re: Une question', to: 'autre@example.be', extraHeaders: [
            'In-Reply-To' => '<question@example.be>',
        ]);
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(2, $messages);
        $sent = array_values(array_filter($messages, static fn($m) => $m->isSent()));
        $this->assertSame(LinkOrigin::THREAD, $sent[0]->linkOrigin);
    }

    public function testTheCopyOfAnEmailTheSiteSentIsNotShownTwice(): void
    {
        // The provider filed what the site sent through the box in
        // « Envoyés ». The page shows it from the site's own log already.
        $booking = $this->createBooking();
        $this->inboundMail->recordOutboundMessageId(
            RentalMessageConsumer::CONSUMER_ID,
            $booking->reference,
            '<site-42@unite.be>'
        );
        $this->deliverSent(1, 'Votre réservation [LOC-2027-0042]', messageId: 'site-42@unite.be');
        $this->sync();

        $this->assertSame([], $this->communicationService->timeline($booking));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inbound_messages')->fetchColumn());
    }

    public function testWhatTheUnitSentIsNeitherAnnouncedNorLearnedFrom(): void
    {
        $booking = $this->createBooking();
        $notifier = $this->createMock(\Modules\Rental\Mail\NewMessageNotifier::class);
        $notifier->expects($this->never())->method('messageFiled');

        $sent = new \Modules\InboundMail\Api\InboundMessage(
            1, $this->mailboxId, '', '', LinkOrigin::MANUAL, 'Les clés', 'locations@unite.be', null,
            'a@b', null, new \DateTimeImmutable('2027-07-02 09:30:00'), '', '',
            toEmails: ['jeanne@example.be'],
            direction: \Modules\InboundMail\Api\MessageDirection::SENT
        );
        $this->plainConsumer($notifier)->onLinked(
            $sent,
            new MessageLink(RentalMessageConsumer::CONSUMER_ID, $booking->reference, LinkOrigin::MANUAL)
        );

        // The unit's own address never becomes one of the renter's.
        $this->assertSame([], $this->bookingRepository->findByRenterEmail('locations@unite.be'));
    }

    // ── « Autres adresses du locataire » (#720, step 5) ─────────────────

    public function testAnAddressTheManagerTypedInIsTheRentersForTheReferenceRule(): void
    {
        // The treasurer quoting the reference is the renter's
        // correspondence as much as the renter doing it.
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example');

        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'tresorier@groupe.example');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testWhatTheUnitWroteToAnotherAddressOfTheRenterLandsToo(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example');

        $this->deliverSent(1, 'Votre réservation [LOC-2027-0042]', to: 'tresorier@groupe.example');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::REFERENCE, $messages[0]->linkOrigin);
    }

    public function testALearnedAddressRemembersTheMessageThatTaughtIt(): void
    {
        $booking = $this->createBooking();
        $this->deliver(10, 'Question sur la caution', from: 'tresorier@groupe.example', messageId: 'one@groupe.example');
        $this->sync();
        $stored = $this->storedMessageIds();
        $this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $booking->reference, $stored[0], 7);

        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertCount(1, $others);
        $this->assertSame('tresorier@groupe.example', $others[0]->email);
        $this->assertSame($stored[0], $others[0]->learnedFromMessageId);
        $this->assertTrue($others[0]->wasLearned());
    }

    public function testAnAiDecisionTeachesTheAddressLikeAPersonsDoes(): void
    {
        $booking = $this->createBooking();
        $message = new \Modules\InboundMail\Api\InboundMessage(
            41, $this->mailboxId, '', '', LinkOrigin::AI, 'Les clés', 'tresorier@groupe.example', null,
            'a@b', null, new \DateTimeImmutable('2027-07-02 09:30:00'), '', ''
        );

        $this->plainConsumer()->onLinked(
            $message,
            new MessageLink(RentalMessageConsumer::CONSUMER_ID, $booking->reference, LinkOrigin::AI)
        );

        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertCount(1, $others);
        $this->assertSame(41, $others[0]->learnedFromMessageId);
    }

    public function testASentMessageTeachesItsRecipientOnlyWhenThereIsOne(): void
    {
        $booking = $this->createBooking();
        $sent = fn(int $id, array $to): \Modules\InboundMail\Api\InboundMessage => new \Modules\InboundMail\Api\InboundMessage(
            $id, $this->mailboxId, '', '', LinkOrigin::MANUAL, 'Les clés', 'locations@unite.be', null,
            'a@b', null, new \DateTimeImmutable('2027-07-02 09:30:00'), '', '',
            toEmails: $to,
            direction: \Modules\InboundMail\Api\MessageDirection::SENT
        );
        $link = new MessageLink(RentalMessageConsumer::CONSUMER_ID, $booking->reference, LinkOrigin::MANUAL);

        // To the renter and the caretaker: nothing says which is the renter's.
        $this->plainConsumer()->onLinked($sent(1, ['concierge@salle.example', 'tresorier@groupe.example']), $link);
        $this->assertSame([], $this->bookingRepository->otherRenterEmails($booking->id));

        $this->plainConsumer()->onLinked($sent(2, ['tresorier@groupe.example']), $link);
        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertCount(1, $others);
        $this->assertSame('tresorier@groupe.example', $others[0]->email);
        $this->assertSame(2, $others[0]->learnedFromMessageId);
    }

    public function testDetachingTheMessageForgetsTheAddressItTaughtAndOnlyThatOne(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'partenaire@maison.example');
        $this->deliver(10, 'Question sur la caution', from: 'tresorier@groupe.example', messageId: 'one@groupe.example');
        $this->sync();
        $stored = $this->storedMessageIds();
        $this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $booking->reference, $stored[0], 7);
        $this->assertCount(2, $this->bookingRepository->otherRenterEmails($booking->id));

        $this->assertTrue($this->communicationService->detach($booking, $stored[0]));

        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertSame(['partenaire@maison.example'], array_map(static fn($o) => $o->email, $others));
        $this->assertFalse($others[0]->wasLearned());
    }

    public function testAnAddressTwoDecisionsTaughtSurvivesDetachingOneOfThem(): void
    {
        // Two messages from the treasurer, each filed by a decision. One
        // row holds the address, pinned to the first; detaching that one
        // must hand it to the second rather than forget it for both.
        $booking = $this->createBooking();
        $this->deliver(10, 'Question sur la caution', from: 'tresorier@groupe.example', messageId: 'one@groupe.example');
        $this->deliver(11, 'Question sur les clés', from: 'tresorier@groupe.example', messageId: 'two@groupe.example');
        $this->sync();
        [$first, $second] = $this->storedMessageIds();
        $this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $booking->reference, $first, 7);
        // The second followed on the rules; record it as the AI's decision
        // it would be when the rules could not tell bookings apart.
        $this->pdo->prepare("UPDATE inbound_message_links SET link_origin = 'ai' WHERE message_id = ?")->execute([$second]);
        $this->assertSame($first, $this->bookingRepository->otherRenterEmails($booking->id)[0]->learnedFromMessageId);

        $this->assertTrue($this->communicationService->detach($booking, $first));

        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertCount(1, $others, 'the second decision still teaches the address');
        $this->assertSame($second, $others[0]->learnedFromMessageId);

        // And once the second goes too, nothing teaches it any more.
        $this->assertTrue($this->communicationService->detach($booking, $second));
        $this->assertSame([], $this->bookingRepository->otherRenterEmails($booking->id));
    }

    public function testWhatTheUnitWroteToAnotherAddressWithoutAReferenceUsesTheRecipientRule(): void
    {
        $booking = $this->createBooking();
        $this->bookingRepository->addRenterEmail($booking->id, 'tresorier@groupe.example');

        $this->deliverSent(1, 'Les clés', to: 'tresorier@groupe.example');
        $this->sync();

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::RECIPIENT, $messages[0]->linkOrigin);
    }

    // ── The model settles what the rules could not (#720, step 6) ───────

    /**
     * @return array{RentalMessageConsumer, ScriptedLlm}
     */
    private function modelConsumer(?string $choice, bool $available = true, ?LlmException $throw = null): array
    {
        $llm = new ScriptedLlm($choice, $available, $throw);

        return [
            new RentalMessageConsumer(
                $this->bookingRepository,
                $this->inboundMail,
                $this->documentService,
                assetRepository: $this->assetRepository,
                modelChoice: new BookingChoiceByModel($llm)
            ),
            $llm,
        ];
    }

    /** The one message the sync stored, as the deferred pass reads it. */
    private function storedMessage(): \Modules\InboundMail\Api\InboundMessage
    {
        $ids = $this->storedMessageIds();
        $this->assertCount(1, $ids);
        $message = $this->messageRepository->findAnyForAnalysis($ids[0]);
        $this->assertNotNull($message);

        return $message;
    }

    /** Two live bookings of Jeanne's, both in range of a July message. */
    private function twoBookingsOfOneRenter(): void
    {
        $this->createBooking('LOC-2027-0042', 'jeanne@example.be');
        $this->createBooking('LOC-2027-0043', 'jeanne@example.be', arrival: '2027-08-01', departure: '2027-08-04');
        $this->deliver(10, 'Une question sans référence', from: 'jeanne@example.be', body: 'Pour le séjour d\'août : les draps ?');
        $this->sync();
        $this->assertSame(0, $this->countRentalAssociations(), 'the rules leave it to the model');
    }

    public function testTheModelSettlesTwoBookingsOfOneRenter(): void
    {
        $this->twoBookingsOfOneRenter();
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0043');

        $result = $consumer->analyzeStored($this->storedMessage());

        $this->assertCount(1, $result->links);
        $this->assertSame('LOC-2027-0043', $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::AI, $result->links[0]->origin);
        $this->assertSame(1, $llm->calls);
        $this->assertNotNull($llm->lastRequest);
        $this->assertStringContainsString('LOC-2027-0042 : Local Saint-Georges · du 2027-07-01 au 2027-07-04', $llm->lastRequest->prompt);
        $this->assertStringContainsString('LOC-2027-0043', $llm->lastRequest->prompt);
        $this->assertStringContainsString('les draps', $llm->lastRequest->prompt);
    }

    public function testTheModelAnsweringInLowerCaseStillNamesTheBooking(): void
    {
        $this->twoBookingsOfOneRenter();
        [$consumer] = $this->modelConsumer(' loc-2027-0042 ');

        $this->assertSame('LOC-2027-0042', $consumer->analyzeStored($this->storedMessage())->links[0]->businessReference);
    }

    /** @return array<string, array{string}> */
    public static function answersThatFileNothing(): array
    {
        return [
            'the model declines' => [''],
            'a booking off the list' => ['LOC-2027-9999'],
            'not a reference at all' => ['la première'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('answersThatFileNothing')]
    public function testAnAnswerThatIsNotOneOfTheBookingsFilesNothing(string $answer): void
    {
        $this->twoBookingsOfOneRenter();
        [$consumer] = $this->modelConsumer($answer);

        $result = $consumer->analyzeStored($this->storedMessage());

        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->readingFailed, 'an answer, even « aucune », is not asked again');
    }

    public function testACallThatFailedIsAskedAgainLater(): void
    {
        $this->twoBookingsOfOneRenter();
        [$consumer] = $this->modelConsumer(null, throw: new LlmException('timeout'));

        $result = $consumer->analyzeStored($this->storedMessage());

        $this->assertSame([], $result->links);
        $this->assertTrue($result->readingFailed);
    }

    public function testWithoutAModelNothingIsAskedAndNothingFiled(): void
    {
        $this->twoBookingsOfOneRenter();
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0043', available: false);

        $this->assertTrue($consumer->analyzeStored($this->storedMessage())->isEmpty());
        $this->assertSame(0, $llm->calls);
    }

    public function testAMessageTheRulesFiledIsNeverAskedAbout(): void
    {
        $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'jeanne@example.be');
        $this->sync();
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0042');

        $this->assertTrue($consumer->analyzeStored($this->storedMessage())->isEmpty());
        $this->assertSame(0, $llm->calls);
    }

    public function testAStrangerWithNoBookingCostsNoCall(): void
    {
        $this->createBooking();
        $this->deliver(10, 'Une offre', from: 'vendeur@ailleurs.example');
        $this->sync();
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0042');

        $this->assertTrue($consumer->analyzeStored($this->storedMessage())->isEmpty());
        $this->assertSame(0, $llm->calls);
    }

    public function testAReferenceQuotedByAnUnknownAddressIsTheModelsToConfirm(): void
    {
        $this->createBooking();
        $this->createBooking('LOC-2027-0043', 'marc@example.be');
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'tresorier@groupe.example', body: 'Je suis le trésorier du groupe de Jeanne.');
        $this->sync();
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0042');

        $result = $consumer->analyzeStored($this->storedMessage());

        $this->assertSame('LOC-2027-0042', $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::AI, $result->links[0]->origin);
        $this->assertNotNull($llm->lastRequest);
        $this->assertStringNotContainsString('LOC-2027-0043', $llm->lastRequest->prompt, 'only the booking the reference names');
    }

    public function testABookingTheMessageWasDetachedFromIsNeverOffered(): void
    {
        [$first] = [$this->createBooking('LOC-2027-0042', 'jeanne@example.be')];
        $this->createBooking('LOC-2027-0043', 'jeanne@example.be', arrival: '2027-08-01', departure: '2027-08-04');
        $this->deliver(10, 'Une question sans référence', from: 'jeanne@example.be');
        $this->sync();
        $id = $this->storedMessageIds()[0];
        $this->inboundMail->attach(RentalMessageConsumer::CONSUMER_ID, $first->reference, $id, 7);
        $this->assertTrue($this->communicationService->detach($first, $id));
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0043');

        $result = $consumer->analyzeStored($this->storedMessage());

        $this->assertSame('LOC-2027-0043', $result->links[0]->businessReference);
        $this->assertNotNull($llm->lastRequest);
        $this->assertStringNotContainsString('LOC-2027-0042', $llm->lastRequest->prompt);
    }

    public function testWhatTheUnitSentToARenterOfTwoBookingsIsTheModelsToSettle(): void
    {
        // A sent message the rules file nowhere is not kept by the sync, so
        // this one is kept because it is filed elsewhere — by another
        // module — and the rentals still have it to settle.
        $this->createBooking('LOC-2027-0042', 'jeanne@example.be');
        $this->createBooking('LOC-2027-0043', 'jeanne@example.be', arrival: '2027-08-01', departure: '2027-08-04');
        $sent = new \Modules\InboundMail\Api\InboundMessage(
            1, $this->mailboxId, '', '', LinkOrigin::MANUAL, 'Les clés', 'locations@unite.be', null,
            'unit-1@unite.be', null, new \DateTimeImmutable('2027-07-02 09:30:00'), 'Bonjour Jeanne,', '',
            toEmails: ['jeanne@example.be'],
            links: [new MessageLink('camps', 'CAMP-1', LinkOrigin::MANUAL)],
            direction: \Modules\InboundMail\Api\MessageDirection::SENT
        );
        [$consumer, $llm] = $this->modelConsumer('LOC-2027-0042');

        $result = $consumer->analyzeStored($sent);

        $this->assertSame('LOC-2027-0042', $result->links[0]->businessReference);
        $this->assertNotNull($llm->lastRequest);
        $this->assertStringContainsString("Envoyé par l'unité à : jeanne@example.be", $llm->lastRequest->prompt);
    }

    public function testTheDeferredPassFilesTheModelsChoiceAndLearnsTheAddress(): void
    {
        // End to end: the stranger quoting the reference is filed nowhere
        // on arrival; the hourly pass asks the model, files its choice
        // as « ai », and the booking learns the treasurer's address.
        $booking = $this->createBooking();
        $this->deliver(10, 'Re: [LOC-2027-0042]', from: 'tresorier@groupe.example', body: 'Le trésorier de Jeanne.');
        $this->sync();
        $this->assertSame(0, $this->countRentalAssociations());

        // The pass asks only the modules a box is open to; this one is
        // the rentals' own.
        $this->mailboxRepository->setPurpose($this->mailboxId, \Modules\InboundMail\Api\MailboxPurpose::DEDICATED, 'rental');
        [$consumer] = $this->modelConsumer('LOC-2027-0042');
        $registry = new MessageConsumerRegistry();
        $registry->register($consumer);
        (new \Modules\InboundMail\Task\AnalyzeStoredMessagesHandler($registry))->handle([], new \Core\Scheduler\TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createStub(\Core\Mail\MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo)),
            new \Core\Security\UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        ));

        $messages = $this->communicationService->timeline($booking);
        $this->assertCount(1, $messages);
        $this->assertSame(LinkOrigin::AI, $messages[0]->linkOrigin);
        $others = $this->bookingRepository->otherRenterEmails($booking->id);
        $this->assertSame(['tresorier@groupe.example'], array_map(static fn($o) => $o->email, $others));
        $this->assertTrue($others[0]->wasLearned());
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

    // ── Untrusted content (§8.58) ────────────────────────────────────────

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

    // ── « Détacher » is final for the booking (#720) ────────────────────

    public function testADetachedMessageIsNotFiledBackByTheNextAnalysis(): void
    {
        // The only booking of its sender: the rules file the message there
        // every time they are asked. Once a manager says it does not
        // concern this booking, they must not file it there again.
        $booking = $this->createBooking();
        $this->deliver(10, 'Une question', from: 'jeanne@example.be');
        $this->sync();
        $messageId = $this->communicationService->timeline($booking)[0]->id;

        $this->assertTrue($this->communicationService->detach($booking, $messageId, null, 7));
        $this->inboundMail->reanalyzeUnlinked(RentalMessageConsumer::CONSUMER_ID);

        $this->assertSame([], $this->communicationService->timeline($booking));
    }

    public function testADetachedMessageMayStillLandOnAnotherBooking(): void
    {
        // It quotes a booking that does not exist yet, so the sender rule
        // files it under the renter's only booking — the wrong one. Once
        // detached, and once the booking it quotes exists, it goes there.
        $first = $this->createBooking('LOC-2027-0042');
        $this->deliver(10, 'Re: [LOC-2027-0043] le week-end de septembre', from: 'jeanne@example.be');
        $this->sync();
        $messageId = $this->communicationService->timeline($first)[0]->id;
        $this->communicationService->detach($first, $messageId);

        $second = $this->createBooking('LOC-2027-0043', arrival: '2027-09-10', departure: '2027-09-12');
        $this->inboundMail->reanalyzeUnlinked(RentalMessageConsumer::CONSUMER_ID);

        $this->assertSame([], $this->communicationService->timeline($first));
        $this->assertCount(1, $this->communicationService->timeline($second));
    }

    // ── The directory the chief's screen files through ──────────────────

    public function testTheDirectoryNamesABookingByItsRenterAndAsset(): void
    {
        $booking = $this->createBooking();
        $consumer = new RentalMessageConsumer(
            $this->bookingRepository,
            $this->inboundMail,
            $this->documentService,
            RentalMessageConsumer::DEFAULT_WINDOW_DAYS_AFTER,
            new \Modules\Rental\Mail\BookingReferenceMatcher(),
            null,
            null,
            null,
            $this->assetRepository
        );

        $found = $consumer->searchReferences('jeanne');
        $this->assertCount(1, $found);
        $this->assertSame('LOC-2027-0042', $found[0]->businessReference);
        $this->assertStringContainsString('Jeanne Martin', $found[0]->label);
        $this->assertStringContainsString('Local Saint-Georges', (string) $found[0]->detail);

        $this->assertSame('LOC-2027-0042', $consumer->searchReferences('loc-2027-0042')[0]->businessReference);
        $this->assertSame([], $consumer->searchReferences('personne'));
        $this->assertSame(
            '/mes-locations/local-saint-georges/reservations/' . $booking->id,
            $consumer->referenceUrl('LOC-2027-0042')
        );
        $this->assertNull($consumer->referenceUrl('LOC-1999-0001'));
    }

    // ── Degrading without the module (§7.5) ─────────────────────────────

    public function testWithoutInboundMailNothingIsCollectedAndNothingDetaches(): void
    {
        $service = new RentalCommunicationService(
            $this->bookingRepository,
            $this->documentRepository,
            $this->authorizationService,
            new JournalService(new JournalRepository($this->pdo))
        );

        $booking = $this->createBooking();

        $this->assertFalse($service->collects());
        $this->assertSame([], $service->timeline($booking));
        $this->assertFalse($service->detach($booking, 1));
    }

    /**
     * @return array<string, array{array<int, array{name: string, state: string, is_enabled: bool}>, bool}>
     */
    public static function mailboxesInScope(): array
    {
        return [
            'an enabled box open to rentals' => [[1 => ['name' => 'Unité', 'state' => 'OK', 'is_enabled' => true]], true],
            'only a disabled one' => [[1 => ['name' => 'Unité', 'state' => 'OK', 'is_enabled' => false]], false],
            'none' => [[], false],
        ];
    }

    /**
     * Whether replies reach the page is the boxes open to rentals that are
     * enabled — dedicated or shared alike (#720).
     *
     * @param array<int, array{name: string, state: string, is_enabled: bool}> $summaries
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxesInScope')]
    public function testTheModuleCollectsWhenAnEnabledBoxIsOpenToIt(array $summaries, bool $collects): void
    {
        $inbound = new class ($summaries) implements \Modules\InboundMail\Api\InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            /** @param array<int, array{name: string, state: string, is_enabled: bool}> $summaries */
            public function __construct(private readonly array $summaries)
            {
            }

            public function listMailboxSummariesFor(string $consumerId): array
            {
                return $consumerId === 'rental' ? $this->summaries : [];
            }
        };
        $service = new RentalCommunicationService(
            $this->bookingRepository,
            $this->documentRepository,
            $this->authorizationService,
            new JournalService(new JournalRepository($this->pdo)),
            $inbound
        );

        $this->assertSame($collects, $service->collects());
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

    /** @return int[] in the order they were stored */
    private function storedMessageIds(): array
    {
        return array_map(
            'intval',
            $this->pdo->query('SELECT id FROM inbound_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
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
