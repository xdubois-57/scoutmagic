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
    private array $moves = [];
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
        $this->moves = [];
        $this->asked = [];
    }

    private function service(?LlmConnectorInterface $llm = null): StayFromMailService
    {
        $inbound = $this->createMock(InboundMailInterface::class);
        $inbound->method('move')->willReturnCallback(
            function (string $consumerId, string $from, string $to, int $messageId): bool {
                $this->moves[] = ['from' => $from, 'to' => $to, 'message' => $messageId];

                return true;
            }
        );

        return new StayFromMailService(
            $this->camps,
            new CampService($this->camps, $this->audit, $this->places),
            new PlaceService($this->places, $this->audit),
            new DuplicatePlaceDetector($this->places, null),
            new MessageReader(),
            $this->settings,
            $inbound,
            $llm
        );
    }

    /** A connector that answers one place name, and remembers what it was asked. */
    private function llmAnswering(string $placeName): LlmConnectorInterface
    {
        $llm = $this->createStub(LlmConnectorInterface::class);
        $llm->method('isAvailable')->willReturn(true);
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
            consumerId: CampsMessageConsumer::CONSUMER_ID,
            businessReference: CampsMessageConsumer::UNSORTED_REFERENCE,
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

        $this->assertSame(
            [[
                'from' => CampsMessageConsumer::UNSORTED_REFERENCE,
                'to' => 'camp-' . $campId,
                'message' => 77,
            ]],
            $this->moves
        );
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
        $this->assertSame([], $this->moves);
    }

    public function testAFailingModelLeavesTheMessageUnsorted(): void
    {
        $this->setAutomatic('1');

        $this->assertNull($this->service($this->llmFailing())->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
        // Unsorted is where a human finds it, which is the whole point of
        // degrading rather than breaking.
        $this->assertSame([], $this->moves);
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
        $this->assertSame('camp-' . $campId, $this->moves[0]['to']);
    }

    public function testWithoutTheConnectorAnUnknownPlaceCreatesNothing(): void
    {
        $this->setAutomatic('1');

        $this->assertNull($this->service()->createFrom($this->message()));
        // The message stays in the unsorted screen, where « Créer un camp
        // depuis ce message » lets a human validate the name before it
        // enters the database.
        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->moves);
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
        $this->assertSame([], $this->moves);
        // And it cost nothing: a message that cannot become a stay is
        // never sent to a provider.
        $this->assertSame([], $this->asked);
    }

    public function testNothingIsCreatedWhenTheSettingIsOff(): void
    {
        $this->setAutomatic('0');

        $this->assertNull($this->service($this->llmAnswering('Domaine de Mozet'))->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->moves);
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
