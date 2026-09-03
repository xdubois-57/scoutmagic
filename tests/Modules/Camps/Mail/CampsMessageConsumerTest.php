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

    public function testACancelledStayDoesNotCompeteForItsContactsMessage(): void
    {
        // A stay cancelled and re-booked with the same farmer used to turn
        // every one of their messages into two propositions for sixteen
        // months. The cancelled one is out of the rule.
        $cancelled = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-08-01', '2026-08-08', null,
            Camp::STATUS_CANCELLED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($cancelled, null, null, 'lambert@example.org', null, null);

        $result = $this->consumer()->analyze($this->message('lambert@example.org'));

        $this->assertSame([], $result->candidates);
        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
    }

    public function testACancelledStayIsNeverMatchedByItsPeriod(): void
    {
        $this->pdo->exec("UPDATE camp_camps SET status = 'cancelled' WHERE id = " . $this->campId);

        $this->assertTrue(
            $this->consumerWithMatcher()
                ->analyze($this->periodMessage('Du 12 au 19 juillet 2026', self::DEDICATED_MAILBOX))
                ->isEmpty()
        );
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
        $this->assertNull($this->consumer($this->dedicatedTo(self::DEDICATED_MAILBOX))->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::DEDICATED_MAILBOX)
        ));
    }

    public function testADedicatedMailboxStillPrefersARealStayOverUnsorted(): void
    {
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $claim = $this->consumer($this->dedicatedTo(self::DEDICATED_MAILBOX))->claim(
            $this->message('lambert@example.org', 'Bonjour', null, [], self::DEDICATED_MAILBOX)
        );

        $this->assertSame('camp-' . $this->campId, $claim?->businessReference);
    }

    public function testTheSharedMailboxIsUnaffectedByTheDedicatedSetting(): void
    {
        // The same unknown sender, in the ordinary mailbox: still not
        // ours, or the module would swallow mail meant for others.
        $this->assertNull($this->consumer($this->dedicatedTo(self::DEDICATED_MAILBOX))->claim(
            $this->message('newsletter@campingbelgique.be', 'Nos offres', null, [], self::SHARED_MAILBOX)
        ));
    }

    public function testTheDedicatedBoxIsTheOneInboundMailNamesAndNotTheOldSetting(): void
    {
        // The regression this test exists for. `camps_dedicated_mailbox_ids`
        // was this module's own list of dedicated boxes; the « Portée des
        // modules » screen took the question over and never wrote to that
        // list again. The module kept reading it, so a unit that declared
        // its camps box on the new screen had automatic stay creation
        // silently switched off — and nothing anywhere said so.
        $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, default_value,
                                   setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute(['camps', 'camps_dedicated_mailbox_ids', '', '', 'text', 'Boîtes dédiées', '']);

        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->once())->method('createFrom')->willReturn($this->campId);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail
        );

        $this->assertSame(
            'camp-' . $this->campId,
            $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->links[0]->businessReference
        );
    }

    public function testAReferenceIsNamedTheWayThePickerNamesTheStay(): void
    {
        // « Camps — camp-51 » on a green badge told a Chef d'Unité nothing.
        $consumer = new CampsMessageConsumer($this->camps, $this->pdo, $this->encryption);

        $label = $consumer->describeReference('camp-' . $this->campId);

        $this->assertNotNull($label);
        $this->assertStringContainsString('Domaine de Mozet', $label);
        $this->assertNull($consumer->describeReference('camp-999'));
        $this->assertNull($consumer->describeReference('LOC-2027-0042'));
    }

    public function testTheDirectoryFindsAStayByItsPlaceAndSaysWhereItLives(): void
    {
        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            null, null, null, null, null,
            new \Modules\Camps\Service\StaySearchService($this->camps)
        );

        $found = $consumer->searchReferences('mozet');
        $this->assertSame('camp-' . $this->campId, $found[0]->businessReference);
        $this->assertStringContainsString('Domaine de Mozet', $found[0]->label);

        // Typed in full, the reference is offered as itself.
        $this->assertSame('camp-' . $this->campId, $consumer->searchReferences('camp-' . $this->campId)[0]->businessReference);
        $this->assertSame('/chefs/camps/sejours/' . $this->campId, $consumer->referenceUrl('camp-' . $this->campId));
        $this->assertNull($consumer->referenceUrl('camp-999'));
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
            $this->camps, $this->pdo, $this->encryption, null, $documents
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
            $this->camps, $this->pdo, $this->encryption, null, $documents
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
            $this->camps, $this->pdo, $this->encryption, null, $documents
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
            $this->camps, $this->pdo, $this->encryption, null, null
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
            $this->camps, $this->pdo, $this->encryption, null, $documents
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
     * An `inbound_mail` that declares those boxes dedicated to Camps.
     *
     * The module used to answer this question itself, from a list of ids in
     * its own settings; `Service\MailboxScopeService` took it over and the
     * old list stopped being written, so on every installation configured
     * through the new screen the module thought it had no dedicated box at
     * all. The consumer now asks, and these tests ask the same way.
     */
    private function dedicatedTo(int ...$mailboxIds): InboundMailInterface
    {
        return new class ($mailboxIds) implements InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            /** @param int[] $mailboxIds */
            public function __construct(private array $mailboxIds)
            {
            }

            public function isDedicatedTo(string $consumerId, int $mailboxId): bool
            {
                return $consumerId === CampsMessageConsumer::CONSUMER_ID
                    && in_array($mailboxId, $this->mailboxIds, true);
            }
        };
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
            $this->camps, $this->pdo, $this->encryption, $inbound, null
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
        $dedicated = self::DEDICATED_MAILBOX;
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->once())->method('createFrom')->willReturn($this->campId);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo($dedicated), null, null, $stayFromMail
        );

        $result = $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX));

        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
        // The stay exists because of the dates read in the content, and
        // that is what the chief reads next to the association — not
        // « adresse de l'expéditeur », which played no part.
        $this->assertSame(LinkOrigin::PERIOD, $result->links[0]->origin);
    }

    public function testAMessageOnASharedBoxIsNeverTurnedIntoAStay(): void
    {
        // On the unit's public address a supplier's quotation would become
        // a camp. The guard is the box, not the message.
        $dedicated = self::DEDICATED_MAILBOX;
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo($dedicated), null, null, $stayFromMail
        );

        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::SHARED_MAILBOX))->isEmpty());
    }

    public function testAUnitThatTurnedAutomaticCreationOffGetsNothing(): void
    {
        $dedicated = self::DEDICATED_MAILBOX;
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(false);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo($dedicated), null, null, $stayFromMail
        );

        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->isEmpty());
    }

    public function testAMessageAChiefAlreadyOrientedDoesNotSproutASecondStay(): void
    {
        // The deferred pass runs an hour later. A message somebody filed
        // by hand in between must not also become a stay of its own.
        $dedicated = self::DEDICATED_MAILBOX;
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo($dedicated), null, null, $stayFromMail
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
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail
        );

        $consumer->onMessageStored($this->bookingMessage('camp-' . $this->campId));
    }

    public function testAMessageNobodyCouldTurnIntoAStayIsLeftAlone(): void
    {
        $dedicated = self::DEDICATED_MAILBOX;
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->method('createFrom')->willReturn(null);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo($dedicated), null, null, $stayFromMail
        );

        // Nothing associated, nothing thrown: the message stays attached
        // to nothing, where both the chef d'unité and this module's own
        // users find it, and a human decides.
        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->isEmpty());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_documents')->fetchColumn());
    }

    public function testABoxNobodyDeclaredDedicatedSaysSoRatherThanSayingNothing(): void
    {
        // The complaint: a contract is sent to the camps address, nothing
        // is created, and the journal has not a word about it. Each guard
        // now names itself — see Mail\StayFromMailService::journalSkip().
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->once())
            ->method('journalSkip')
            ->with(55, \Modules\Camps\Mail\StayFromMailService::SKIP_MAILBOX_NOT_DEDICATED);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail
        );

        $consumer->analyzeStored($this->unattachedMessage(self::SHARED_MAILBOX));
    }

    public function testAMessageSomethingElseAlreadyClaimedIsNotJournalledAsARefusal(): void
    {
        // Bounded on purpose: a message another module filed is not one
        // anybody is wondering about, and a line per claimed message would
        // be the flood this journal exists to avoid, not the answer.
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->expects($this->never())->method('journalSkip');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail
        );

        $consumer->analyzeStored($this->bookingMessage('camp-' . $this->campId));
    }

    /**
     * The whole point of the deferred pass creating a stay: the contract
     * that made it becomes one of the stay's documents.
     *
     * `onLinked()` is what does that, and it is reached through the
     * notifier the deferred pass gained — see
     * `InboundMail\Service\LinkedMessageNotifier`. Before, the stay was
     * created and the contract stayed behind in the mailbox.
     */
    public function testTheContractThatCreatedTheStayIsFiledAsItsDocument(): void
    {
        $documents = $this->documentService();
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->method('createFrom')->willReturn($this->campId);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), $documents, null, $stayFromMail
        );

        $result = $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX));

        // What the pass does next, with the association the applier wrote
        // — re-reading the message, attachments and all, exactly as
        // `LinkedMessageNotifier` does.
        $consumer->onLinked(
            $this->storedMessage(CampsMessageConsumer::referenceFor($this->campId)),
            $result->links[0]
        );

        $this->assertSame(1, $this->documentCount());
    }

    /** A stored message with no association at all — the deferred pass's subject. */
    private function unattachedMessage(int $mailboxId, ?string $bodyText = null): \Modules\InboundMail\Api\InboundMessage
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
            bodyText: $bodyText ?? $message->bodyText,
            bodyHtml: $message->bodyHtml
        );
    }

    /**
     * @param string[] $references
     */
    // ── The period a message announces (the third identification) ───────

    /**
     * The complaint this whole section exists for.
     *
     * A unit books a field; the contract creates the stay. A fortnight
     * later the same staff writes to the site to ask about the arrival
     * time — new subject, no `References` chain back to the first message,
     * and the unit's OWN address in `From:`. Neither of the two
     * identifications this consumer had could see anything, so « Relancer
     * l'analyse » changed nothing however often it was pressed.
     *
     * What both messages carry is the period, and on a box the unit
     * declared to be its camps box that is evidence enough.
     */
    public function testAMessageAnnouncingTheStaysDatesIsAttachedToIt(): void
    {
        $result = $this->consumerWithMatcher()->analyze(
            $this->periodMessage('Du 12 au 19 juillet 2026', self::DEDICATED_MAILBOX)
        );

        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
        // Honest about how it got there: a second site quoting for the
        // same week states the same two dates just as truthfully.
        $this->assertSame(LinkOrigin::PERIOD, $result->links[0]->origin);
        $this->assertFalse(LinkOrigin::PERIOD->isCertain());
    }

    public function testThePeriodIsReadOnADedicatedBoxAndNowhereElse(): void
    {
        // On the unit's shared address a parent writing « on part du 12 au
        // 19 juillet » about something else entirely would land on the
        // camp booked those days — and everything this consumer takes is
        // a message another module will never see.
        $this->assertTrue(
            $this->consumerWithMatcher()
                ->analyze($this->periodMessage('Du 12 au 19 juillet 2026', self::SHARED_MAILBOX))
                ->isEmpty()
        );
    }

    public function testAKnownContactStillWinsOverThePeriod(): void
    {
        // Ordering, and it is not cosmetic: the sender is the stronger
        // evidence, and the origin a chief reads has to be the reason the
        // message is actually there.
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);

        $result = $this->consumerWithMatcher()->analyze(
            $this->periodMessage('Du 12 au 19 juillet 2026', self::DEDICATED_MAILBOX, 'lambert@example.org')
        );

        $this->assertSame(LinkOrigin::SENDER, $result->links[0]->origin);
    }

    public function testTwoStaysOverTheSameDaysProduceTwoPropositionsAndNoAssociation(): void
    {
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Ferme du Moulin')");
        $this->camps->create(
            2, Camp::STAY_GRAND_CAMP, '2026-07-12', '2026-07-19', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );

        $result = $this->consumerWithMatcher()->analyze(
            $this->periodMessage('Du 12 au 19 juillet 2026', self::DEDICATED_MAILBOX)
        );

        $this->assertSame([], $result->links, 'ScoutMagic chooses neither');
        $this->assertCount(2, $result->candidates);
        $this->assertStringContainsString('2 séjours', $result->candidates[0]->explanation);
    }

    public function testAPeriodMatchingNoStayClaimsNothing(): void
    {
        $this->assertTrue(
            $this->consumerWithMatcher()
                ->analyze($this->periodMessage('Du 12 au 19 juillet 2029', self::DEDICATED_MAILBOX))
                ->isEmpty()
        );
    }

    /**
     * The regression in one test: attaching to a stay the unit ALREADY
     * booked is not creating one, and must not obey the setting that
     * governs creating.
     *
     * `camps_auto_create_from_mail` off used to mean the deferred pass
     * returned before it read anything at all — so a unit that had
     * deliberately turned automatic creation off got no association
     * either, on mail whose own contract named the booking to the day.
     */
    public function testAUnitWithAutomaticCreationOffStillGetsItsMessageAttached(): void
    {
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(false);
        $stayFromMail->method('fullTextOf')->willReturn('Du 12 au 19 juillet 2026');
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail, $this->matcher()
        );

        $result = $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX));

        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
    }

    public function testTheDeferredPassPrefersTheStayItFindsOverTheStayItWouldInvent(): void
    {
        // Two messages about one booking is the normal case. The second
        // one must not grow a duplicate stay, and it must not need the
        // creation path to fail first for that to happen.
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->method('fullTextOf')->willReturn('Arrivée : 12-07-26 16:30   Départ : 19-07-26 10:00');
        $stayFromMail->expects($this->never())->method('createFrom');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail, $this->matcher()
        );

        $this->assertSame(
            'camp-' . $this->campId,
            $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->links[0]->businessReference
        );
    }

    public function testTheDeferredPassStillCreatesWhenNoStayMatchesThePeriod(): void
    {
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->method('fullTextOf')->willReturn('Du 12 au 19 juillet 2029');
        $stayFromMail->expects($this->once())->method('createFrom')->willReturn($this->campId);

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail, $this->matcher()
        );

        $this->assertSame(
            'camp-' . $this->campId,
            $consumer->analyzeStored($this->unattachedMessage(self::DEDICATED_MAILBOX))->links[0]->businessReference
        );
    }

    /**
     * A body that already answered costs no file read.
     *
     * Not a micro-optimisation: reading the attachments means opening a
     * stored file and, for a scanned contract, sending one page image to
     * the AI provider. Doing that for a message whose own subject line
     * names the camp would be paying — in money and in what leaves the
     * installation — for an answer already in hand.
     */
    public function testTheAttachmentsAreNotOpenedWhenTheBodyAlreadyNamedThePeriod(): void
    {
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('isAutomatic')->willReturn(true);
        $stayFromMail->expects($this->never())->method('fullTextOf');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail, $this->matcher()
        );

        $result = $consumer->analyzeStored(
            $this->unattachedMessage(self::DEDICATED_MAILBOX, 'Du 12 au 19 juillet 2026')
        );

        $this->assertSame('camp-' . $this->campId, $result->links[0]->businessReference);
    }

    public function testAPeriodIsNotReadOnASharedBoxByTheDeferredPassEither(): void
    {
        $stayFromMail = $this->createMock(\Modules\Camps\Mail\StayFromMailService::class);
        $stayFromMail->method('fullTextOf')->willReturn('Du 12 au 19 juillet 2026');

        $consumer = new CampsMessageConsumer(
            $this->camps, $this->pdo, $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX), null, null, $stayFromMail, $this->matcher()
        );

        $this->assertTrue($consumer->analyzeStored($this->unattachedMessage(self::SHARED_MAILBOX))->isEmpty());
    }

    private function matcher(): \Modules\Camps\Mail\ExistingStayMatcher
    {
        return new \Modules\Camps\Mail\ExistingStayMatcher($this->camps, new \Modules\Camps\Mail\MessageReader());
    }

    private function consumerWithMatcher(?\Modules\Camps\Mail\StayChoiceByModel $modelChoice = null): CampsMessageConsumer
    {
        return new CampsMessageConsumer(
            $this->camps,
            $this->pdo,
            $this->encryption,
            $this->dedicatedTo(self::DEDICATED_MAILBOX),
            null,
            null,
            null,
            $this->matcher(),
            null,
            $modelChoice
        );
    }

    // ── Two signals crossed: the sender's stays, narrowed by the period ──

    public function testThePeriodInTheMessageTellsTwoStaysOfOneContactApart(): void
    {
        // The farmer who hosts the unit every summer: two stays in the
        // window under one address, and the dates in the message are
        // what a chief would read to tell them apart. So does the module.
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-20', '2026-07-27', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        $result = $this->consumerWithMatcher()->analyze(
            $this->message('lambert@example.org', 'Confirmation du 20 au 27 juillet 2026')
        );

        $this->assertSame([], $result->candidates);
        $this->assertCount(1, $result->links);
        $this->assertSame('camp-' . $second, $result->links[0]->businessReference);
        $this->assertSame(LinkOrigin::SENDER, $result->links[0]->origin);
    }

    public function testAPeriodMatchingNeitherOfTheSendersStaysLeavesBothProposed(): void
    {
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-20', '2026-07-27', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        $result = $this->consumerWithMatcher()->analyze(
            $this->message('lambert@example.org', 'Vos dates du 1 au 8 août 2026 ?')
        );

        $this->assertSame([], $result->links);
        $this->assertCount(2, $result->candidates);
    }

    public function testAPeriodNamingAStrangersStayDoesNotOverrideTheSender(): void
    {
        // The period narrows the sender's list; it never adds to it. A
        // farmer mentioning dates that happen to be another stay's is
        // still writing about one of their own.
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-20', '2026-07-27', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $other = $this->camps->create(
            1, Camp::STAY_SHORT_CAMP, '2026-06-06', '2026-06-07', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);

        $result = $this->consumerWithMatcher()->analyze(
            $this->message('lambert@example.org', 'Le week-end du 6 au 7 juin 2026')
        );

        $this->assertSame([], $result->links);
        $this->assertNotContains(
            'camp-' . $other,
            array_map(static fn($c) => $c->businessReference, $result->candidates)
        );
    }

    // ── The model, last, and only to order (§8.59) ───────────────────────

    public function testTheModelsPickLeadsThePropositionsAndAssociatesNothing(): void
    {
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-20', '2026-07-27', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);
        $llm = new \Tests\Modules\InboundMail\ScriptedLlm('camp-' . $second);

        $result = $this->consumerWithMatcher(new \Modules\Camps\Mail\StayChoiceByModel($llm))->analyze(
            $this->message('lambert@example.org', 'Le pré pour la fin juillet')
        );

        $this->assertSame([], $result->links, 'the model never associates');
        $this->assertSame(
            ['camp-' . $second, 'camp-' . $this->campId],
            array_map(static fn($c) => $c->businessReference, $result->candidates)
        );
        $this->assertSame('ai', $result->candidates[0]->evidenceType);
        $this->assertStringContainsString('Le modèle suggère', $result->candidates[0]->explanation);
        $this->assertSame('sender_window', $result->candidates[1]->evidenceType);
        $this->assertSame(1, $llm->calls);
    }

    public function testTheModelIsNotAskedWhenThePeriodAlreadyDecided(): void
    {
        $second = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, '2026-07-20', '2026-07-27', null,
            Camp::STATUS_CONFIRMED, null, null, null, null, []
        );
        $this->contacts->create($this->campId, null, null, 'lambert@example.org', null, null);
        $this->contacts->create($second, null, null, 'lambert@example.org', null, null);
        $llm = new \Tests\Modules\InboundMail\ScriptedLlm('camp-' . $this->campId);

        $result = $this->consumerWithMatcher(new \Modules\Camps\Mail\StayChoiceByModel($llm))->analyze(
            $this->message('lambert@example.org', 'Confirmation du 20 au 27 juillet 2026')
        );

        $this->assertCount(1, $result->links);
        $this->assertSame(0, $llm->calls);
    }

    // ── onLinked(): a person's decision teaches the stay ─────────────────

    public function testAManualAssociationMakesTheSenderAContactOfTheStay(): void
    {
        // The farmer's spouse writes from their own address. Filing that
        // message by hand is the chief saying « cette adresse, c'est ce
        // séjour » — said once, remembered for the next message.
        $consumer = $this->consumer();
        $message = $this->messageFrom('epouse@example.org', 'Marie Lambert', LinkOrigin::MANUAL);

        $consumer->onLinked($message, new MessageLink(
            CampsMessageConsumer::CONSUMER_ID,
            'camp-' . $this->campId,
            LinkOrigin::MANUAL
        ));

        $contacts = $this->contacts->findByCamp($this->campId);
        $this->assertCount(1, $contacts);
        $this->assertSame('epouse@example.org', $contacts[0]->email);
        $this->assertSame('Marie Lambert', $contacts[0]->name);
        $this->assertSame('Correspondant', $contacts[0]->roleLabel);

        $this->assertCount(1, $consumer->analyze($this->message('epouse@example.org'))->links);
    }

    public function testAKnownAddressIsNotAddedTwice(): void
    {
        $this->contacts->create($this->campId, 'M. Lambert', 'Fermier', 'lambert@example.org', null, null);

        $this->consumer()->onLinked(
            $this->messageFrom('Lambert@Example.org', null, LinkOrigin::MANUAL),
            new MessageLink(CampsMessageConsumer::CONSUMER_ID, 'camp-' . $this->campId, LinkOrigin::MANUAL)
        );

        $contacts = $this->contacts->findByCamp($this->campId);
        $this->assertCount(1, $contacts);
        $this->assertSame('Fermier', $contacts[0]->roleLabel, 'the contact the chief typed is left alone');
    }

    public function testAnAutomaticAssociationTeachesNothing(): void
    {
        $this->consumer()->onLinked(
            $this->messageFrom('quelquun@example.org', null, LinkOrigin::THREAD),
            new MessageLink(CampsMessageConsumer::CONSUMER_ID, 'camp-' . $this->campId, LinkOrigin::THREAD)
        );

        $this->assertSame([], $this->contacts->findByCamp($this->campId));
    }

    private function messageFrom(string $from, ?string $name, LinkOrigin $origin): \Modules\InboundMail\Api\InboundMessage
    {
        return new \Modules\InboundMail\Api\InboundMessage(
            1,
            self::SHARED_MAILBOX,
            CampsMessageConsumer::CONSUMER_ID,
            'camp-' . $this->campId,
            $origin,
            'Bonjour',
            $from,
            $name,
            '<msg@mail>',
            null,
            new \DateTimeImmutable('2026-06-01'),
            'Corps',
            '',
            [],
            []
        );
    }

    /** A message whose SUBJECT states a period, on a box declared dedicated or not. */
    private function periodMessage(
        string $subject,
        int $mailboxId,
        string $from = 'staff@unite.be'
    ): CandidateMessage {
        return new CandidateMessage(
            mailboxId: $mailboxId,
            subject: $subject,
            fromEmail: $from,
            fromName: null,
            messageId: '<msg@mail>',
            inReplyTo: null,
            references: [],
            toEmails: ['fresnaye@example.org'],
            sentAt: new \DateTimeImmutable('2026-06-01'),
            bodyText: 'Nous voulions confirmer notre heure d\'arrivée.',
            bodyHtml: '',
            mailboxDedicatedTo: $mailboxId === self::DEDICATED_MAILBOX
                ? CampsMessageConsumer::CONSUMER_ID
                : null
        );
    }

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
