<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\CampsMessageConsumer;
use Modules\Camps\Mail\MessageReader;
use Modules\Camps\Mail\StayFromMailService;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmException;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmResponse;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * `camps_auto_create_from_mail`, both halves.
 *
 * What matters here is as much what is NOT created as what is: this module
 * answers ambiguity with silence everywhere else, and a stay invented from
 * a message nobody can check would be the one place it did not.
 *
 * **The sender's display name is not a place name and never becomes one.**
 * A farmer signs their own e-mails, so naming a new place after the
 * `From:` header wrote a natural person's name into `camp_places.name` — a
 * clear-text column justified by "a place is not a natural person"
 * (ARCHITECTURE.md §8.67). A name now comes out of the message BODY, read
 * by the model, or it does not come at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class StayFromMailServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private PlaceRepository $places;
    private AuditService $audit;
    private SettingService $settings;
    /** @var list<array{from: string, to: string, message: int}> */
    /** @var list<LlmRequest> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->asked = [];
    }

    private function service(
        ?LlmConnectorInterface $llm = null,
        ?\Modules\Camps\Mail\AttachmentTextReader $attachments = null
    ): StayFromMailService {
        return new StayFromMailService(
            $this->camps,
            new CampService($this->camps, $this->audit, $this->places),
            new PlaceService($this->places, $this->audit),
            new DuplicatePlaceDetector($this->places, null),
            new MessageReader(),
            $this->settings,
            $llm,
            $attachments,
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        );
    }

    // ── The booking that arrives as a PDF ───────────────────────────────

    /**
     * The message that prompted all of this: a one-word covering note and
     * a contract in the attachment.
     *
     * Reading `subject + bodyText` alone, this service saw nothing at all
     * in such a message and refused it for want of dates — which is exactly
     * what a unit reported: « j'ai envoyé un contrat de location à camps et
     * il ne s'est rien passé ».
     */
    public function testAContractInAPdfBecomesAStay(): void
    {
        $storagePath = sys_get_temp_dir() . '/stayfrompdf_' . uniqid();
        mkdir($storagePath . '/inbound', 0700, true);

        try {
            $files = new \Core\File\FileRepository($this->pdo);
            $bytes = (string) file_get_contents(
                dirname(__DIR__, 3) . '/fixtures/pdf/camp_booking_contract.pdf'
            );
            file_put_contents($storagePath . '/inbound/contrat.pdf', $bytes);
            $fileId = $files->create(
                'inbound/contrat.pdf',
                'contrat.pdf',
                'application/pdf',
                strlen($bytes),
                'chief',
                'inbound_mail',
                null,
                false
            );

            $reader = new \Modules\Camps\Mail\AttachmentTextReader(new \Core\File\StoredFileReader(
                $files,
                new \Core\File\EncryptedFileStorageService(
                    $files,
                    new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
                    $storagePath
                ),
                $storagePath
            ));

            $campId = $this->service($this->llmAnswering('Centre de camp Le Grand Pré'), $reader)->createFrom(
                $this->messageWithAttachment($fileId, strlen($bytes))
            );

            $this->assertNotNull($campId);
            $camp = $this->camps->findById($campId);
            $this->assertSame('2026-09-18', $camp?->startDate);
            $this->assertSame('2026-09-20', $camp?->endDate);
            $this->assertSame('Centre de camp Le Grand Pré', $this->places->findById((int) $camp?->placeId)?->name);

            // And the model was shown the contract, not just « Bonjour, ».
            $this->assertStringContainsString('Arrivee: 18-09-26', $this->asked[0]->prompt);
        } finally {
            foreach (glob($storagePath . '/inbound/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($storagePath . '/inbound');
            @rmdir($storagePath);
        }
    }

    public function testWithoutTheAttachmentReaderTheSameMessageSaysItHasNoDates(): void
    {
        // The behaviour before, kept as a test so the difference is on the
        // record: a one-word body has nothing to read, and now says so.
        $this->assertNull($this->service()->createFrom($this->messageWithAttachment(1, 100)));
        $this->assertSame(
            'no_dates',
            json_decode((string) $this->journalEntries()[0]['context'], true)['reason']
        );
    }

    private function messageWithAttachment(int $fileId, int $sizeBytes): InboundMessage
    {
        $base = $this->message(fromName: 'Emeline', subject: 'Contrat de location', body: 'Bonjour,');

        return new InboundMessage(
            id: $base->id,
            mailboxId: $base->mailboxId,
            consumerId: '',
            businessReference: '',
            linkOrigin: $base->linkOrigin,
            subject: $base->subject,
            fromEmail: $base->fromEmail,
            fromName: $base->fromName,
            messageId: $base->messageId,
            inReplyTo: $base->inReplyTo,
            sentAt: $base->sentAt,
            bodyText: $base->bodyText,
            bodyHtml: '',
            toEmails: [],
            attachments: [new \Modules\InboundMail\Api\InboundAttachment(
                1,
                $base->id,
                $fileId,
                'contrat.pdf',
                'application/pdf',
                $sizeBytes,
                'hash'
            )]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalEntries(): array
    {
        return (new \Core\Journal\JournalRepository($this->pdo))->search();
    }

    // ── Saying WHY nothing was created ──────────────────────────────────

    /**
     * The whole complaint, in one test: automatic creation is a chain of
     * five guards, any of which is an ordinary « non », and from outside
     * all five looked identical — nothing happened, nowhere.
     */
    public function testAMessageWithNoDatesSaysSoInTheJournal(): void
    {
        // What a real one looks like: a rental contract as a PDF, with a
        // one-word body. The dates are in the attachment, which this path
        // does not read — and until now nothing said that either.
        $this->service()->createFrom($this->message(subject: 'Contrat de location', body: 'Bonjour,'));

        $entry = $this->journalEntries()[0];
        $this->assertSame('camps_stay_from_mail_skipped', $entry['event_type']);
        $this->assertSame('no_dates', json_decode((string) $entry['context'], true)['reason']);
        $this->assertStringContainsString('période de séjour', $entry['description']);
    }

    public function testAMessageWhosePlaceCouldNotBeNamedSaysSoInTheJournal(): void
    {
        // Dates, but no connector and no known place: the most confusing
        // of the five, because everything the unit can see about the
        // message looks right.
        $this->service()->createFrom(
            $this->message(fromName: 'Luc', subject: 'Réservation', body: 'Du 12 au 19 juillet 2028.')
        );

        $this->assertSame(
            'no_place',
            json_decode((string) $this->journalEntries()[0]['context'], true)['reason']
        );
    }

    public function testTurningAutomaticCreationOffIsWrittenDownToo(): void
    {
        $this->setAutomatic('0');

        $this->service()->createFrom($this->message(body: 'Du 12 au 19 juillet 2028.'));

        $this->assertSame(
            'not_automatic',
            json_decode((string) $this->journalEntries()[0]['context'], true)['reason']
        );
    }

    public function testAStayThatIsCreatedIsWrittenDownAsWell(): void
    {
        $campId = $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom(
            $this->message(body: 'Du 12 au 19 juillet 2028, Domaine de Mozet.')
        );

        $this->assertNotNull($campId);
        $entry = $this->journalEntries()[0];
        $this->assertSame('camps_stay_created_from_mail', $entry['event_type']);
        $this->assertSame($campId, json_decode((string) $entry['context'], true)['camp_id']);
    }

    public function testTheJournalNeverNamesTheSenderOrTheSubject(): void
    {
        // A journal entry travels in the diagnostic archive (§7.9): an
        // internal message id and a reason, and nothing else.
        $this->service()->createFrom($this->message(subject: 'Contrat Fresnaye', body: 'Bonjour,'));

        $entry = $this->journalEntries()[0];
        $whole = $entry['description'] . '|' . (string) $entry['context'];
        $this->assertStringNotContainsString('@', $whole);
        $this->assertStringNotContainsString('Fresnaye', $whole);
    }
    /** A connector that answers one place name, and remembers what it was asked. */
    private function llmAnswering(string $placeName): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willReturnCallback(
            function (LlmRequest $request) use ($placeName): LlmResponse {
                $this->asked[] = $request;

                return new LlmResponse(
                    (string) json_encode(['place_name' => $placeName]),
                    ['place_name' => $placeName],
                    100,
                    10
                );
            }
        );

        return $llm;
    }

    private function llmFailing(): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->method('isTierAvailable')->willReturn(true);
        $llm->method('complete')->willThrowException(new LlmException('provider down'));

        return $llm;
    }

    private function setAutomatic(string $value): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES ('camps_auto_create_from_mail', ?, 'camps', 'boolean', 'x', 'x')"
        );
        $stmt->execute([$value]);
    }

    private function message(
        string $fromName = 'Domaine de Mozet',
        string $subject = 'Confirmation de réservation',
        string $body = 'Bonjour, nous vous confirmons le Domaine de Mozet du 12 au 19 juillet 2028. '
            . 'Le prix est de 2450 €.'
    ): InboundMessage {
        return new InboundMessage(
            id: 77,
            mailboxId: 2,
            // Attached to nothing: this service only ever runs on a
            // message no stay claimed, which since IT-07 means one with no
            // association at all rather than one filed under a reserved
            // `unsorted` reference.
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::SENDER,
            subject: $subject,
            fromEmail: 'info@mozet.be',
            fromName: $fromName,
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: $body,
            bodyHtml: ''
        );
    }

    // ── With the connector: the body names the place ────────────────

    public function testTheModelNamesTheNewPlaceOutOfTheMessageBody(): void
    {
        $this->setAutomatic('1');

        $campId = $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom(
            // The sender is a person. The body names a field. Only one of
            // the two may ever end up in camp_places.name.
            $this->message(fromName: 'Jean-Pierre Lambert')
        );

        $this->assertNotNull($campId);
        $camp = $this->camps->findById($campId);
        $this->assertNotNull($camp);
        $this->assertSame('2028-07-12', $camp->startDate);
        $this->assertSame('2028-07-19', $camp->endDate);
        $this->assertSame(245000, $camp->priceCents);
        // Never confirmed: nobody has looked at it yet.
        $this->assertSame(Camp::STATUS_TO_CONFIRM, $camp->status);
        $this->assertSame('Domaine de Mozet', $this->places->findById($camp->placeId)?->name);
    }

    public function testTheMessageIsFiledUnderTheStayItProduced(): void
    {
        $this->setAutomatic('1');

        $campId = $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message());

        // The association itself is NOT made here any more: this service
        // returns the new stay's id, `CampsMessageConsumer::analyzeStored()`
        // returns it as an analysis result, and
        // `Service\AnalysisResultApplier` writes the one association — one
        // place that creates associations rather than two.
        $this->assertNotNull($this->camps->findById($campId));
    }

    public function testTheHistorySaysTheStayCameFromTheMail(): void
    {
        $this->setAutomatic('1');

        $campId = (int) $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message());

        $entries = $this->audit->page(CampService::ENTITY_TYPE, $campId, 1, 10)->entries;
        $this->assertNotSame([], $entries);
        $this->assertSame(AuditSource::Email, $entries[0]->source);
    }

    public function testTheModelIsAskedForAVenueAndNeverForAPerson(): void
    {
        $this->setAutomatic('1');

        $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message());

        $this->assertCount(1, $this->asked);
        $request = $this->asked[0];
        // The outbound flow the RGPD page describes: the text of the
        // message itself reaches the provider.
        $this->assertStringContainsString('du 12 au 19 juillet 2028', $request->prompt);
        $system = (string) $request->systemPrompt;
        $this->assertStringContainsString('nom de personne', $system);
        $this->assertStringContainsString('expéditeur', $system);
        $this->assertStringContainsString('chaîne vide', $system);
    }

    public function testASecondMessageAboutTheSameBookingDoesNotCreateASecondStay(): void
    {
        $this->setAutomatic('1');
        $service = $this->service($this->llmAnswering('Domaine de Mozet'));

        $first = $service->createFrom($this->message());
        $second = $service->createFrom(
            $this->message(subject: 'Votre facture', body: 'Séjour du 12 au 19 juillet 2028 à Mozet.')
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, count($this->camps->findByPlace((int) $this->camps->findById((int) $first)?->placeId)));
        $this->assertCount(1, $this->places->findAllVisible());
    }

    public function testAPlaceTheModuleAlreadyKnowsIsReusedRatherThanDuplicated(): void
    {
        $this->setAutomatic('1');
        $existing = $this->places->create('Domaine de Mozet', null, '5340', 'Mozet', 'Belgique', null);

        $campId = (int) $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message());

        $this->assertSame($existing, $this->camps->findById($campId)?->placeId);
        $this->assertCount(1, $this->places->findAllVisible());
    }

    // ── With the connector, but no usable answer ────────────────────

    public function testAModelThatNamesNothingCreatesNothing(): void
    {
        $this->setAutomatic('1');

        // The sender is called « Domaine de Mozet » and the body says so
        // too — and still nothing is created, because the only source of a
        // NEW name is an answer the model was sure enough to give.
        $this->assertNull($this->service($this->llmAnswering(''))->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
    }

    public function testAFailingModelLeavesTheMessageUnsorted(): void
    {
        $this->setAutomatic('1');

        $this->assertNull($this->service($this->llmFailing())->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
        // Returning null is the whole point of degrading rather than
        // breaking: the message stays attached to nothing, where both the
        // chef d'unité and this module's own users find it.
    }

    public function testAFailingModelStillJoinsAPlaceTheModuleAlreadyKnows(): void
    {
        $this->setAutomatic('1');
        $existing = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $campId = $this->service($this->llmFailing())->createFrom($this->message());

        $this->assertNotNull($campId);
        $this->assertSame($existing, $this->camps->findById($campId)?->placeId);
        $this->assertCount(1, $this->places->findAllVisible());
    }

    public function testAnAnswerTooShortOrLookingLikeAnAddressIsRefused(): void
    {
        $this->setAutomatic('1');

        // « Luc » is an initial, a first name, or what a mail client made
        // of an empty display name — never a camp site.
        $this->assertNull($this->service($this->llmAnswering('Luc'))->createFrom($this->message()));
        $this->assertNull($this->service($this->llmAnswering('info@mozet.be'))->createFrom($this->message()));
        $this->assertNull($this->service($this->llmAnswering('   '))->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
    }

    // ── Without the connector: match only, never create ─────────────

    public function testWithoutTheConnectorAKnownPlaceStillTakesTheStay(): void
    {
        $this->setAutomatic('1');
        $existing = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $campId = $this->service()->createFrom($this->message());

        $this->assertNotNull($campId);
        $this->assertSame($existing, $this->camps->findById($campId)?->placeId);
        $this->assertCount(1, $this->places->findAllVisible());
        $this->assertNotNull($this->camps->findById($campId));
    }

    public function testWithoutTheConnectorAnUnknownPlaceCreatesNothing(): void
    {
        $this->setAutomatic('1');

        $this->assertNull($this->service()->createFrom($this->message()));
        // The message stays in the unsorted screen, where « Créer un camp
        // depuis ce message » lets a human validate the name before it
        // enters the database.
        $this->assertSame([], $this->places->findAllVisible());
    }

    public function testTheSenderDisplayNameIsNeverWrittenDownAsAPlace(): void
    {
        $this->setAutomatic('1');

        foreach ([null, $this->llmAnswering(''), $this->llmFailing()] as $llm) {
            $this->service($llm)->createFrom($this->message(fromName: 'Domaine de Mozet'));
            $this->service($llm)->createFrom($this->message(fromName: 'Jean-Pierre Lambert'));
        }

        $this->assertSame([], $this->places->findAllVisible());
    }

    // ── Dates, and the setting ──────────────────────────────────────

    public function testAMessageWithoutUsableDatesCreatesNothing(): void
    {
        $this->setAutomatic('1');

        // A lone date is far more often a meeting than a departure, so
        // MessageReader refuses it — and so does this.
        $campId = $this->service($this->llmAnswering('Domaine de Mozet'))->createFrom(
            $this->message(body: 'Bonjour, pouvons-nous en parler le 12 juillet 2028 ?')
        );

        $this->assertNull($campId);
        $this->assertSame([], $this->places->findAllVisible());
        // And it cost nothing: a message that cannot become a stay is
        // never sent to a provider.
        $this->assertSame([], $this->asked);
    }

    public function testNothingIsCreatedWhenTheSettingIsOff(): void
    {
        $this->setAutomatic('0');

        $this->assertNull($this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->asked);
    }

    // ── The pre-filled form asks the same questions ─────────────────

    public function testTheSameReadingIsAvailableToPreFillTheForm(): void
    {
        // The point of one readValues(): what the form is pre-filled with
        // and what the automatic stay would have been are the same
        // sentence read once.
        $this->setAutomatic('0');

        $this->assertSame(
            [
                'place_name' => 'Domaine de Mozet',
                'start_date' => '2028-07-12',
                'end_date' => '2028-07-19',
                'price' => number_format(2450, 2, ',', ' '),
            ],
            $this->service($this->llmAnswering('Domaine de Mozet'))
                ->readValues($this->message(fromName: 'Jean-Pierre Lambert'))
        );
    }

    public function testWithoutTheConnectorTheFormIsPreFilledWithoutAPlaceName(): void
    {
        $this->setAutomatic('0');

        $values = $this->service()->readValues($this->message(fromName: 'Jean-Pierre Lambert'));

        // Dates and price still, name never: a chief types the name, which
        // is exactly the validation this path exists for.
        $this->assertSame('', $values['place_name']);
        $this->assertSame('2028-07-12', $values['start_date']);
    }

    public function testTheFormIsPointedAtAPlaceTheModuleAlreadyKnows(): void
    {
        $existing = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $this->assertSame($existing, $this->service()->matchExistingPlaceId('Domaine de Mozet'));
        $this->assertNull($this->service()->matchExistingPlaceId('Ferme du Moulin'));
        $this->assertNull($this->service()->matchExistingPlaceId(''));
    }

    public function testTheSenderNameRemainsAHintForRECOGNISINGAKnownPlace(): void
    {
        $existing = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $service = $this->service();

        // Recognising a farmer the unit has camped with writes nothing
        // anywhere, which is what makes it safe when naming a new place
        // from the same string is not.
        $this->assertSame($existing, $service->matchPlaceIdFor($this->message(), ''));
        $this->assertNull($service->matchPlaceIdFor($this->message(fromName: 'Jean-Pierre Lambert'), ''));
    }

    public function testWhetherAPlaceMayBeNamedAtAllFollowsTheConnector(): void
    {
        $this->assertFalse($this->service()->canNamePlaces());
        $this->assertTrue($this->service($this->llmAnswering('Domaine de Mozet'))->canNamePlaces());
    }
}
