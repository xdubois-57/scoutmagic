<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageLink;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class CampsMessageConsumerTest extends TestCase
{
    private const SHARED_MAILBOX = 1;
    private const DEDICATED_MAILBOX = 2;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampRepository $camps;
    private ContactRepository $contacts;
    private SettingService $settings;
    private int $campId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $this->encryption);
        $this->contacts = new ContactRepository($this->pdo, $this->encryption);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        $this->campId = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-12', '2026-07-19', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
    }

    // ── The shared mailbox: narrow on purpose ───────────────────────

    public function testAnUnknownSenderInASharedMailboxIsNotClaimed(): void
    {
        // Everything this consumer takes is a message another module will
        // never see. A camps module claiming loosely would quietly
        // swallow the unit's mail.
        $this->assertNull($this->consumer()->claim($this->message('inconnu@example.org')));
    }

    public function testASubjectMentioningAPlaceIsNotEnough(): void
    {
        // Never a place name in a subject: it is not a claim, it is a
        // coincidence waiting to happen.
        $this->assertNull($this->consumer()->claim(
            $this->message('inconnu@example.org', 'Domaine de Mozet — disponibilités')
        ));
    }

    public function testAKnownContactWritingInTheWindowIsClaimed(): void
    {
        $this->contacts->create($this->campId, 'Mme Lambert', null, 'lambert@example.org', null, null);

        $claim = $this->consumer()->claim($this->message('lambert@example.org'));

        $this->assertNotNull($claim);
        $this->assertSame('camp-' . $this->campId, $claim->businessReference);
        $this->assertSame(LinkOrigin::SENDER, $claim->origin);
    }

    public function testTheSenderMatchIsCaseInsensitive(): void
    {
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $this->assertNotNull($this->consumer()->claim($this->message('Lambert@Example.ORG')));
    }

    public function testAKnownContactWritingYearsLaterIsNotClaimed(): void
    {
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        // Next year's enquiry from the same farmer must not land on last
        // year's camp.
        $this->assertNull($this->consumer()->claim(
            $this->message('lambert@example.org', 'Bonjour', '2029-03-01')
        ));
    }

    public function testTwoStaysMatchingOneSenderClaimNothing(): void
    {
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-08-01', '2026-08-08', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        // Putting a farmer's message on whichever of two stays sorted
        // first is worse than leaving it where it was: the chief reading
        // the wrong stay has no way to know it is the wrong one.
        $this->assertNull($this->consumer()->claim($this->message('lambert@example.org')));
    }

    public function testAReplyInAKnownThreadIsClaimed(): void
    {
        $inbound = $this->createStub(InboundMailInterface::class);
        $inbound->method('findReferenceByThread')->willReturn('camp-' . $this->campId);

        $claim = $this->consumer($inbound)->claim(
            $this->message('quelquun@example.org', 'RE: terrain', null, ['<abc@mail>'])
        );

        $this->assertNotNull($claim);
        $this->assertSame(LinkOrigin::THREAD, $claim->origin);
    }

    // ── Ambiguity produces propositions, not silence (IT-07) ────────

    public function testTwoStaysOfOneContactProduceTwoPropositionsAndNoAssociation(): void
    {
        // A farmer who has hosted the unit twice has two stays under one
        // address. Guessing which one this message is about would put it on
        // the wrong page with no way for the reader to tell — but saying
        // « c'est l'un de ces deux » is a middle the module used to lack.
        $second = $this->camps->create(
            1,
            Camp::STAY_GRAND_CAMP,
            '2026-07-20',
            '2026-07-27',
            null,
            Camp::STATUS_CONFIRMED,
            null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        $result = $this->consumer()->analyze(
            $this->message('lambert@example.org', 'Bonjour', null, [], self::SHARED_MAILBOX)
        );

        $this->assertSame([], $result->links, 'ScoutMagic chooses neither');
        $this->assertCount(2, $result->candidates);
        $this->assertStringContainsString('2 séjours', $result->candidates[0]->explanation);
    }

    public function testAPropositionNamesTheStayTheWayEveryOtherScreenDoes(): void
    {
        $second = $this->camps->create(
            1,
            Camp::STAY_GRAND_CAMP,
            '2026-07-20',
            '2026-07-27',
            null,
            Camp::STATUS_CONFIRMED,
            null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        $labels = array_map(
            static fn($c) => $c->label,
            $this->consumer()->analyze(
                $this->message('lambert@example.org', 'Bonjour', null, [], self::SHARED_MAILBOX)
            )->candidates
        );

        // Through Service\CampLabels, like every other camps screen: a
        // second way of writing a stay's dates would drift from the first
        // within a season, and this label is read next to those screens.
        $this->assertStringContainsString('Grand camp', implode(' | ', $labels));
        $this->assertStringContainsString('juillet', implode(' | ', $labels));
    }

    // ── The dedicated mailbox: everything, then sorted by hand ──────

    public function testADedicatedMailboxNoLongerInventsAnAssociation(): void
    {
        // It used to claim everything under a reserved `unsorted`
        // reference — a bucket masquerading as a stay, with its own
        // retention, screen and purge task. The message is stored either
        // way now (§8.58); what a dedicated box buys is that this module's
        // own users see all of it, which the mailbox configuration says
        // rather than a fake business object.
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);

        $this->assertNull($this->consumer()->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::DEDICATED_MAILBOX)
        ));
    }

    public function testADedicatedMailboxStillPrefersARealStayOverUnsorted(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $claim = $this->consumer()->claim(
            $this->message('lambert@example.org', 'Bonjour', null, [], self::DEDICATED_MAILBOX)
        );

        $this->assertSame('camp-' . $this->campId, $claim?->businessReference);
    }

    public function testTheSharedMailboxIsUnaffectedByTheDedicatedSetting(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);

        // The same unknown sender, in the ordinary mailbox: still not
        // ours, or the module would swallow mail meant for others.
        $this->assertNull($this->consumer()->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::SHARED_MAILBOX)
        ));
    }

    public function testSeveralDedicatedMailboxesAreSupported(): void
    {
        $this->setDedicatedMailboxes('2, 7 ,9');

        $this->assertSame([2, 7, 9], $this->consumer()->dedicatedMailboxIds());
    }

    public function testNoDedicatedMailboxIsTheDefault(): void
    {
        $this->assertSame([], $this->consumer()->dedicatedMailboxIds());
    }

    public function testOnlyAStayReferenceReadsBackAsAStay(): void
    {
        $this->assertSame('camp-42', CampsMessageConsumer::referenceFor(42));
        $this->assertSame(42, CampsMessageConsumer::campIdFromReference('camp-42'));
        // Anything else — another module's reference, or the reserved
        // `unsorted` this module no longer mints — is not one of ours.
        $this->assertNull(CampsMessageConsumer::campIdFromReference('unsorted'));
        $this->assertNull(CampsMessageConsumer::campIdFromReference('LOC-2027-0042'));
    }

    // ── Storing a message on a stay that is no longer there ─────────

    public function testAMessageFiledUnderADeletedStayIsNotAttached(): void
    {
        // A reference names a stay; it does not prove one still exists.
        // The stay can be deleted or merged away between the claim and the
        // sync that stores the message — and camp_documents.camp_id is a
        // foreign key, so attaching would fail the whole synchronisation
        // pass over a row nobody can even see.
        $documents = $this->documentService();
        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, null, $documents
        );

        $consumer->onMessageStored($this->storedMessage('camp-99999'));

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn()
        );
    }

    public function testAMessageFiledUnderALiveStayStillAttachesItsFile(): void
    {
        $documents = $this->documentService();
        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, null, $documents
        );

        $consumer->onMessageStored($this->storedMessage('camp-' . $this->campId));

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn()
        );
    }

    // ── onUnlinked(): what arrived with the message leaves with it ──────

    public function testDetachingAMessageTakesBackTheDocumentItBrought(): void
    {
        // Reassigning a message used to leave its camp_documents row on the
        // first stay: invisible to whoever runs the new one, unexplainable
        // to whoever runs the old one.
        $documents = $this->documentService();
        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, null, $documents
        );
        $message = $this->storedMessage('camp-' . $this->campId);
        $consumer->onMessageStored($message);
        $this->assertSame(1, $this->documentCount());

        $consumer->onUnlinked($message, new MessageLink(
            CampsMessageConsumer::CONSUMER_ID,
            'camp-' . $this->campId,
            LinkOrigin::SENDER
        ));

        $this->assertSame(0, $this->documentCount());
    }

    public function testARepositoryThisModuleWasNotGivenIsNotAnError(): void
    {
        // The scheduled path registers the consumer without a document
        // service — there is nobody to file for during a synchronisation.
        // Detaching then has nothing to do, and must not throw.
        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, null, null
        );

        $consumer->onUnlinked($this->storedMessage('camp-' . $this->campId), new MessageLink(
            CampsMessageConsumer::CONSUMER_ID,
            'camp-' . $this->campId,
            LinkOrigin::SENDER
        ));

        $this->assertSame(0, $this->documentCount());
    }

    public function testAReferenceThatNamesNoStayTakesNothingBack(): void
    {
        $documents = $this->documentService();
        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, null, $documents
        );
        $message = $this->storedMessage('camp-' . $this->campId);
        $consumer->onMessageStored($message);

        // A reference that does not name a stay at all — deliberately
        // written out rather than borrowed from a constant, so this stays
        // true whatever reserved references the module gains or loses.
        $consumer->onUnlinked($message, new MessageLink(
            CampsMessageConsumer::CONSUMER_ID,
            'sans-objet',
            LinkOrigin::SENDER
        ));

        $this->assertSame(
            1,
            $this->documentCount(),
            'a reference that names no stay leaves the real one alone'
        );
    }

    // ── What the triage screen asks every consumer ──────────────────────

    public function testNothingMoreIsLearnedOnceTheMessageIsOnDisk(): void
    {
        // Everything this module recognises was in the headers and the body
        // on arrival; reading a stranger's attachment for more is guesswork.
        $result = $this->consumer()->analyzeStored($this->storedMessage('camp-' . $this->campId));

        $this->assertSame([], $result->links);
        $this->assertSame([], $result->candidates);
    }

    public function testTheModuleSaysWhatItRecognisesAndWhoWouldSeeIt(): void
    {
        // Shown before a shared mailbox is opened to this module: an empty
        // answer there would be a silent "trust me".
        $consumer = $this->consumer();

        $this->assertNotSame([], $consumer->describeEvidence());
        $this->assertNotSame('', $consumer->triageAudienceLabel());
    }

    public function testTheAudienceIsCountedOnTheYearInEffect(): void
    {
        // The figure is the only guard-rail on opening a mailbox to this
        // module, so it is read rather than estimated.
        $this->assertGreaterThanOrEqual(0, $this->consumer()->triageAudienceCount());
    }

    private function documentCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn();
    }

    private function documentService(): \Modules\Camps\Service\DocumentService
    {
        return new \Modules\Camps\Service\DocumentService(
            new \Modules\Camps\Repository\DocumentRepository($this->pdo),
            new \Core\File\AttachedFileRemover(
                new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()
            ),
            new \Core\File\UploadHandler(new \Core\File\FileRepository($this->pdo), sys_get_temp_dir()),
            new \Core\Audit\AuditService(
                new \Core\Audit\AuditRepository($this->pdo, $this->encryption)
            )
        );
    }

    private function storedMessage(string $reference): \Modules\InboundMail\Api\InboundMessage
    {
        $fileId = (new \Core\File\FileRepository($this->pdo))->create(
            'camps/piece-jointe.pdf',
            'piece-jointe.pdf',
            'application/pdf',
            10,
            'chief',
            'inbound_mail',
            null
        );

        return new \Modules\InboundMail\Api\InboundMessage(
            1,
            self::SHARED_MAILBOX,
            CampsMessageConsumer::CONSUMER_ID,
            $reference,
            LinkOrigin::SENDER,
            'Contrat',
            'lambert@example.org',
            null,
            '<msg@mail>',
            null,
            new \DateTimeImmutable('2026-06-01'),
            'Corps',
            '',
            [],
            [new \Modules\InboundMail\Api\InboundAttachment(
                1,
                1,
                $fileId,
                'piece-jointe.pdf',
                'application/pdf',
                10,
                str_repeat('a', 64)
            )]
        );
    }

    /**
     * Written straight into `settings`: SettingService::set() refuses a
     * key the module registration has not declared yet, and this test
     * exercises the consumer rather than the settings machinery.
     */
    private function setDedicatedMailboxes(string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['camps', 'camps_dedicated_mailbox_ids', $value, '', 'text', 'Boîtes dédiées', '']);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
    }

    // ── canRead(): who may open a stay's mail (§8.3, IT-02) ─────────────

    /**
     * The camps answer is a role floor, not a per-stay grant — a stay has
     * no manager list of its own, and every chef is in the staff of the
     * unit that runs it. What matters is where the floor sits: an
     * intendant is NOT a chef, and an inbound attachment is not a
     * quartermaster's business.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function rolesAndAnswers(): array
    {
        return [
            'public' => ['public', false],
            'identified' => ['identified', false],
            'intendant' => ['intendant', false],
            'chief' => ['chief', true],
            'admin' => ['admin', true],
            'superadmin' => ['superadmin', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolesAndAnswers')]
    public function testOnlyAChiefAndAboveMayReadAStaysMail(string $role, bool $expected): void
    {
        $this->assertSame(
            $expected,
            $this->consumer()->canRead(CampsMessageConsumer::referenceFor(1), [], $role)
        );
    }

    public function testARoleNobodyRecognisesIsTreatedAsThePublicOne(): void
    {
        // Fail closed. A role string this build does not know is a build
        // mismatch or a tampered session, and neither is a reason to open
        // a stay's correspondence.
        $this->assertFalse($this->consumer()->canRead(CampsMessageConsumer::referenceFor(1), [], 'inexistant'));
        $this->assertFalse($this->consumer()->canRead(CampsMessageConsumer::referenceFor(1), [], ''));
    }

    private function consumer(?InboundMailInterface $inbound = null): CampsConsumerV1Adapter
    {
        return new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings, $inbound, null
        );
    }

    // ── The unsorted pile can become a stay (§ camps_auto_create_from_mail)

    private function bookingMessage(string $reference): \Modules\InboundMail\Api\InboundMessage
    {
        return new \Modules\InboundMail\Api\InboundMessage(
            id: 55,
            mailboxId: self::DEDICATED_MAILBOX,
            consumerId: CampsMessageConsumer::CONSUMER_ID,
            businessReference: $reference,
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Confirmation',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: 'Du 12 au 19 juillet 2028.',
            bodyHtml: '',
            // `links` is what the deferred pass reads to decide whether
            // anybody has already oriented this message — carrying only
            // `businessReference` would leave that guard permanently blind.
            links: $reference === ''
                ? []
                : [new \Modules\InboundMail\Api\MessageLink(
                    CampsMessageConsumer::CONSUMER_ID,
                    $reference,
                    LinkOrigin::SENDER
                )]
        );
    }

    public function testAMessageNoStayClaimedIsOfferedToTheStayCreator(): void
    {
        // On the DEFERRED pass, where a stored message and a bounded
        // hourly job already meet — `createFrom()` reads the body and may
        // call the AI connector, neither of which CandidateMessage carries.
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->once())->method('createFrom')->willReturn($this->campId);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        $result = $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX));

        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
    }

    public function testAMessageOnASharedBoxIsNeverTurnedIntoAStay(): void
    {
        // On the unit's public address a supplier's quotation would become
        // a camp. The guard is the box, not the message.
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::SHARED_MAILBOX))->isEmpty());
    }

    public function testAUnitThatTurnedAutomaticCreationOffGetsNothing(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(false);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->isEmpty());
    }

    public function testAMessageAChiefAlreadyOrientedDoesNotSproutASecondStay(): void
    {
        // The deferred pass runs an hour later. A message somebody filed
        // by hand in between must not also become a stay of its own.
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        $this->assertTrue(
            $consumer->analyzeStored($this->bookingMessage('camp-' . $this->campId))->isEmpty()
        );
    }

    public function testAMessageAlreadyOnAStayIsNotOfferedToTheStayCreator(): void
    {
        // It has a stay; creating one from it would be a duplicate of the
        // very thing it is already filed under.
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsConsumerV1Adapter(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        $consumer->onMessageStored($this->bookingMessage('camp-' . $this->campId));
    }

    public function testAMessageNobodyCouldTurnIntoAStayIsLeftAlone(): void
    {
        $this->setDedicatedMailboxes((string) self::DEDICATED_MAILBOX);
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->method('createFrom')->willReturn(null);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption, $this->settings,
            null, null, null, $stayFromMail
        );

        // Nothing associated, nothing thrown: the message stays attached
        // to nothing, where both the chef d'unité and this module's own
        // users find it, and a human decides.
        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->isEmpty());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn());
    }

    /** A stored message with no association at all — the deferred pass's subject. */
    private function unattachedMessage(int $mailboxId): \Modules\InboundMail\Api\InboundMessage
    {
        $message = $this->bookingMessage('');

        return new \Modules\InboundMail\Api\InboundMessage(
            id: $message->id,
            mailboxId: $mailboxId,
            consumerId: '',
            businessReference: '',
            linkOrigin: $message->linkOrigin,
            subject: $message->subject,
            fromEmail: $message->fromEmail,
            fromName: $message->fromName,
            messageId: $message->messageId,
            inReplyTo: $message->inReplyTo,
            sentAt: $message->sentAt,
            bodyText: $message->bodyText,
            bodyHtml: $message->bodyHtml
        );
    }

    /**
     * @param string[] $references
     */
    private function message(
        string $from,
        string $subject = 'Bonjour',
        ?string $sentAt = null,
        array $references = [],
        int $mailboxId = self::SHARED_MAILBOX
    ): CandidateMessage {
        return new CandidateMessage(
            $mailboxId,
            $subject,
            $from,
            null,
            '<msg@mail>',
            $references !== [] ? $references[0] : null,
            $references,
            ['camps@unite.be'],
            new \DateTimeImmutable($sentAt ?? '2026-06-01'),
            'Corps du message',
            ''
        );
    }
}
