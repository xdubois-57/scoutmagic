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
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * `camps_auto_create_from_mail`, both halves.
 *
 * The setting shipped with a description promising two behaviours and no
 * code behind either. What matters here is as much what is NOT created as
 * what is: this module answers ambiguity with silence everywhere else, and
 * a stay invented from a message nobody can check would be the one place
 * it did not.
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
    }

    private function service(): StayFromMailService
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
            $inbound
        );
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
        string $body = 'Bonjour, nous vous confirmons du 12 au 19 juillet 2028. Le prix est de 2450 €.'
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

    // ── Automatic mode ──────────────────────────────────────────────

    public function testAMessageWithDatesAndAPlaceBecomesAStay(): void
    {
        $this->setAutomatic('1');

        $campId = $this->service()->createFrom($this->message());

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

        $campId = $this->service()->createFrom($this->message());

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

        $campId = (int) $this->service()->createFrom($this->message());

        $entries = $this->audit->page(CampService::ENTITY_TYPE, $campId, 1, 10)->entries;
        $this->assertNotSame([], $entries);
        $this->assertSame(AuditSource::Email, $entries[0]->source);
    }

    public function testAPlaceTheModuleAlreadyKnowsIsReusedRatherThanDuplicated(): void
    {
        $this->setAutomatic('1');
        $existing = $this->places->create('Domaine de Mozet', null, '5340', 'Mozet', 'Belgique', null);

        $campId = (int) $this->service()->createFrom($this->message());

        $this->assertSame($existing, $this->camps->findById($campId)?->placeId);
        $this->assertCount(1, $this->places->findAllVisible());
    }

    public function testAMessageWithoutUsableDatesCreatesNothing(): void
    {
        $this->setAutomatic('1');

        // A lone date is far more often a meeting than a departure, so
        // MessageReader refuses it — and so does this.
        $campId = $this->service()->createFrom(
            $this->message(body: 'Bonjour, pouvons-nous en parler le 12 juillet 2028 ?')
        );

        $this->assertNull($campId);
        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->moves);
    }

    public function testAMessageWhoseSenderNamesNoPlaceCreatesNothing(): void
    {
        $this->setAutomatic('1');

        $this->assertNull($this->service()->createFrom($this->message(fromName: 'Luc')));
        $this->assertNull($this->service()->createFrom($this->message(fromName: '')));
        // A mail client that put the address in the display name names no
        // place either — a camp site called « info@mozet.be » would be worse
        // than none.
        $this->assertNull($this->service()->createFrom($this->message(fromName: 'info@mozet.be')));
        $this->assertSame([], $this->places->findAllVisible());
    }

    public function testASecondMessageAboutTheSameBookingDoesNotCreateASecondStay(): void
    {
        $this->setAutomatic('1');

        $first = $this->service()->createFrom($this->message());
        $second = $this->service()->createFrom(
            $this->message(subject: 'Votre facture', body: 'Séjour du 12 au 19 juillet 2028.')
        );

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->camps->findByPlace((int) $this->camps->findById((int) $first)?->placeId));
    }

    // ── Manual mode ─────────────────────────────────────────────────

    public function testNothingIsCreatedWhenTheSettingIsOff(): void
    {
        $this->setAutomatic('0');

        $this->assertNull($this->service()->createFrom($this->message()));
        $this->assertSame([], $this->places->findAllVisible());
        $this->assertSame([], $this->moves);
    }

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
            $this->service()->readValues($this->message())
        );
    }

    public function testTheFormIsPointedAtAPlaceTheModuleAlreadyKnows(): void
    {
        $existing = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        $this->assertSame($existing, $this->service()->matchExistingPlaceId('Domaine de Mozet'));
        $this->assertNull($this->service()->matchExistingPlaceId('Ferme du Moulin'));
        $this->assertNull($this->service()->matchExistingPlaceId(''));
    }
}
