<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Controller;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Camps\Controller\CampsChiefController;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Repository\DocumentRepository;
use Modules\Camps\Repository\LinkRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Repository\ReviewRepository;
use Modules\Camps\Service\CampAlbumService;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\DuplicatePlaceDetector;
use Modules\Camps\Service\PlaceArchiveService;
use Modules\Camps\Service\PlaceService;
use Modules\Camps\Service\ReviewService;
use Modules\Camps\Service\SectionDescriber;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * Creating a stay and its place from one form.
 *
 * One form writing two rows is the whole point of the screen — and the
 * whole risk: whatever it writes before the first refusal stays written.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampsChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private PlaceRepository $places;
    private CampRepository $camps;
    private CampsChiefController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->places = new PlaceRepository($this->pdo);
        $this->camps = new CampRepository($this->pdo, $encryption);
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );
        $albums = new CampAlbumService($audit, null);
        $reviews = new ReviewRepository($this->pdo);

        $this->controller = new CampsChiefController(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['camps' => dirname(__DIR__, 4) . '/modules/camps/views']
            ),
            $this->places,
            $this->camps,
            new PlaceService($this->places, $audit),
            new CampService($this->camps, $audit),
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings,
            new ContactRepository($this->pdo, $encryption),
            new LinkRepository($this->pdo),
            new DocumentRepository($this->pdo),
            $albums,
            $reviews,
            new ReviewService($reviews, $audit, $this->places),
            new DuplicatePlaceDetector($this->places, null),
            new PlaceArchiveService($this->places, $this->camps, $audit)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'chief@test.com', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function post(array $overrides = []): \Core\Http\Response
    {
        $body = array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            // A brand-new place, and "create it anyway" already answered so
            // the duplicate screen does not stand in the way.
            'place_id' => '0',
            'confirm_new' => '1',
            'place_name' => 'Ferme de la Vallée',
            'place_city' => 'Mozet',
            'stay_type' => 'grand_camp',
            'status' => 'confirmed',
            'start_date' => '2027-07-12',
            'end_date' => '2027-07-19',
        ], $overrides);

        return $this->controller->store(
            new Request('POST', '/chefs/camps', [], $body, [], []),
            []
        );
    }

    private function countPlaces(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM camp_places')->fetchColumn();
    }

    public function testAValidFormCreatesThePlaceAndTheStay(): void
    {
        $response = $this->post();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(1, $this->countPlaces());
        $this->assertCount(1, $this->camps->findByPlace($this->places->findAllVisible()[0]->id));
    }

    public function testAStayRefusedByValidationLeavesNoPlaceBehind(): void
    {
        // The form writes the place first. A stay refused afterwards used
        // to leave that place standing — a row nobody asked for, on the
        // places list, in the duplicate detector's candidates, and on the
        // map, with no stay to explain it.
        $response = $this->post(['start_date' => '', 'end_date' => '', 'year_only' => '']);

        // The form comes back rather than redirecting to an empty one, so
        // the chief still has what they typed (see § "les formulaires
        // gardent la saisie").
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->countPlaces(), 'A refused stay must leave no orphan place.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_camps')->fetchColumn());
    }

    public function testAnImpossibleDateRangeLeavesNoPlaceBehindEither(): void
    {
        $this->post(['start_date' => '2027-07-19', 'end_date' => '2027-07-12']);

        $this->assertSame(0, $this->countPlaces());
    }

    public function testAnUnknownStayTypeLeavesNoPlaceBehindEither(): void
    {
        $this->post(['stay_type' => 'colonie-de-vacances']);

        $this->assertSame(0, $this->countPlaces());
    }

    public function testAStayCannotBeAttachedToAnArchivedPlace(): void
    {
        // An archived place is off every ordinary screen, and the picker
        // never offers it — so the stay would be invisible the moment it
        // was saved.
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);
        $this->places->archive($placeId, true);

        $this->post(['place_id' => (string) $placeId, 'confirm_new' => '0']);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM camp_camps')->fetchColumn());
    }

    public function testAStayIsStillAttachableToALivePlace(): void
    {
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);

        $this->post(['place_id' => (string) $placeId, 'confirm_new' => '0']);

        $this->assertCount(1, $this->camps->findByPlace($placeId));
    }

    // ── The form keeps what was typed ───────────────────────────────

    public function testARefusedCreationRendersTheFormAgainWithTheTypedValues(): void
    {
        // A chief who described a new place, ticked its sections and wrote
        // a note used to lose all of it because one date was wrong, and
        // the message explaining why arrived on a blank form.
        $response = $this->post([
            'start_date' => '2027-07-19',
            'end_date' => '2027-07-12',
            'place_name' => 'Ferme de la Vallée',
            'address' => 'Chemin du Tronquoy 4',
            'postal_code' => '5340',
            'city' => 'Mozet',
            'participant_count' => '65',
            'booked_by_name' => 'Thomas Dupont',
            'price' => '2450,00',
        ]);
        $html = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Ferme de la Vallée', $html);
        $this->assertStringContainsString('Chemin du Tronquoy 4', $html);
        $this->assertStringContainsString('5340', $html);
        $this->assertStringContainsString('Mozet', $html);
        $this->assertStringContainsString('Thomas Dupont', $html);
        $this->assertStringContainsString('2450,00', $html);
        // And the reason it came back, on the screen that shows the field.
        $this->assertStringContainsString('alert-danger', $html);
    }

    public function testARefusedCreationKeepsTheTickedSections(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (id, desk_code, label, sort_order) VALUES (1, 'LOU', 'Louveteaux', 1)");
        $this->pdo->exec("INSERT INTO sections (id, name, desk_code, age_branch_id, is_active) VALUES (7, 'La Meute', 'MEU', 1, 1)");

        $response = $this->post([
            'start_date' => '', 'end_date' => '', 'year_only' => '',
            'section_ids' => ['7'],
        ]);

        $this->assertMatchesRegularExpression(
            '/id="section-7"[^>]*checked/',
            (string) preg_replace('/\s+/', ' ', $response->getBody())
        );
    }

    public function testARefusedPlaceEditRendersTheFormAgainWithTheTypedValues(): void
    {
        $placeId = $this->places->create('Ferme du Bois', null, null, 'Mozet', null, null);

        $response = $this->controller->updatePlace(
            new Request('POST', '/chefs/camps/lieux/' . $placeId, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'place_name' => '',
                'address' => 'Chemin du Tronquoy 4',
                'city' => 'Mozet',
            ], [], []),
            ['id' => (string) $placeId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Chemin du Tronquoy 4', $response->getBody());
        $this->assertStringContainsString('alert-danger', $response->getBody());
    }

    // ── Past stays are paginated ────────────────────────────────────

    public function testThePastStaysSettingIsActuallyRead(): void
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        foreach ([2019, 2020, 2021] as $year) {
            $this->camps->create(
                $placeId,
                \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
                $year . '-07-12',
                $year . '-07-19',
                null,
                \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
                null,
                null,
                null,
                null,
                []
            );
        }

        $this->pdo->exec(
            "INSERT INTO settings (setting_key, setting_value, module_id, setting_type, label, description)
             VALUES ('camps_past_stays_per_page', '1', 'camps', 'number', 'x', 'x')"
        );

        $response = $this->controller->showPlace(
            new Request('GET', '/chefs/camps/lieux/' . $placeId, [], [], [], []),
            ['id' => (string) $placeId]
        );
        $html = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('2021', $html, 'The most recent past stay is the one shown.');
        $this->assertStringNotContainsString('2019', $html, 'Only one stay per page.');
        // And a way to reach the rest.
        $this->assertStringContainsString('?statut=&amp;page=2', $html);
    }

    // ── The stay page ───────────────────────────────────────────────

    private function stayPage(int $campId): string
    {
        return $this->controller->showCamp(
            new Request('GET', '/chefs/camps/sejours/' . $campId, [], [], [], []),
            ['id' => (string) $campId]
        )->getBody();
    }

    private function aStay(): int
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);

        return $this->camps->create(
            $placeId,
            \Modules\Camps\Repository\Camp::STAY_GRAND_CAMP,
            '2019-07-12',
            '2019-07-19',
            null,
            \Modules\Camps\Repository\Camp::STATUS_CONFIRMED,
            null,
            null,
            null,
            null,
            []
        );
    }

    public function testEachContactOffersItsEditAndDeleteControls(): void
    {
        $campId = $this->aStay();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $contacts = new ContactRepository($this->pdo, $encryption);
        $contactId = $contacts->create($campId, 'Mme Lambert', 'Propriétaire', 'lambert@example.org', null, null);

        $html = $this->stayPage($campId);

        // The two routes existed and nothing on any screen reached them.
        $this->assertStringContainsString('#contact-' . $contactId . '-modal', $html);
        $this->assertStringContainsString('action="/chefs/camps/contacts/' . $contactId . '"', $html);
        $this->assertStringContainsString(
            'action="/chefs/camps/contacts/' . $contactId . '/supprimer"',
            $html
        );
        $this->assertStringContainsString('bi-pencil', $html);
        $this->assertStringContainsString('bi-trash', $html);
        // Every destructive POST states its consequence, on the <form>.
        $this->assertMatchesRegularExpression(
            '/<form[^>]*contacts\/' . $contactId . '\/supprimer"[^>]*data-confirm="/',
            (string) preg_replace('/\s+/', ' ', $html)
        );
    }

    public function testTheEditDialogOpensOnTheRoleTheContactHolds(): void
    {
        $campId = $this->aStay();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $contacts = new ContactRepository($this->pdo, $encryption);
        $contacts->create($campId, 'Mme Lambert', 'Gestionnaire', null, '081 58 00 00', null);

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        $this->assertMatchesRegularExpression('/value="gestionnaire" selected/', $html);
    }

    public function testAReviewCanBeTakenBackOff(): void
    {
        $campId = $this->aStay();
        (new ReviewRepository($this->pdo))->save($campId, 4, 'Terrain plat, eau au robinet.', null);

        $html = $this->stayPage($campId);

        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/avis/supprimer"',
            $html
        );
        $this->assertStringContainsString("Retirer l'avis", html_entity_decode($html));
    }

    /**
     * The stay page keeps exactly one primary button of its own.
     *
     * It used to carry three: the page_header's « Modifier », « Ajouter
     * le contact » at the bottom of a six-field form, and « Enregistrer
     * l'avis » at the bottom of another — three ways of telling a chef
     * d'unité « the main action is here » on one screen (design.md §7.4).
     * Both forms are dialogs now, opened by outline buttons, and each
     * holds the single primary of the dialog it lives in.
     */
    /**
     * Whether to offer « Anonymiser » is decided by the controller, not by
     * a role comparison spelled out in the template. The route itself is
     * role_min: admin, so a chief offered the link would only be refused
     * on the next click — and a template naming the role strings that pass
     * is a rule nobody can change in one place.
     */
    public function testAChiefIsNotOfferedTheContactAnonymisation(): void
    {
        $campId = $this->aStay();
        $contacts = new ContactRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $contacts->create($campId, 'M. Delvaux', 'Propriétaire', null, null, null);

        AuthSession::login(1, 'chief@test.com', 'chief');
        $this->assertStringNotContainsString('Anonymiser', $this->stayPage($campId));

        AuthSession::login(1, 'admin@test.com', 'admin');
        $this->assertStringContainsString('Anonymiser', $this->stayPage($campId));
    }

    public function testTheStayPageOffersItsTwoFormsAsDialogs(): void
    {
        $campId = $this->aStay();

        $html = (string) preg_replace('/\s+/', ' ', $this->stayPage($campId));

        foreach (['#contact-add-modal', '#review-modal'] as $target) {
            $this->assertStringContainsString('data-bs-target="' . $target . '"', $html, $target);
        }
        // The forms themselves are untouched — same action, same method.
        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/contacts" id="contact-add-form"',
            $html
        );
        $this->assertStringContainsString(
            'action="/chefs/camps/sejours/' . $campId . '/avis" id="review-form"',
            $html
        );
        // Their submit buttons reach them from the dialog footer.
        $this->assertStringContainsString('form="contact-add-form"', $html);
        $this->assertStringContainsString('form="review-form"', $html);
    }

    public function testTheStayPageShowsOneUnhiddenPrimaryAtATime(): void
    {
        $campId = $this->aStay();

        $html = $this->stayPage($campId);
        // Everything after the first dialog is inside a `.modal`, so what
        // is left in the page body is the page_header's own action.
        $body = substr($html, 0, strpos($html, '<div class="modal fade"') ?: strlen($html));

        $this->assertSame(1, substr_count($body, 'btn-primary'), $body);
    }

    // ── « Créer un camp depuis ce message » ─────────────────────────

    /**
     * A controller wired the way public/index.php wires it when
     * inbound_mail is enabled, with one unsorted message to read.
     *
     * @param list<array{from: string, to: string, message: int}> $moves
     */
    private function controllerWithUnsortedMessage(array &$moves): CampsChiefController
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $settings = new SettingService(new SettingRepository($this->pdo));
        $sections = new SectionService(
            \Core\Database\Connection::withPdo($this->pdo),
            $encryption,
            new \Core\Badge\MemberBadgeRepository($this->pdo)
        );
        $reviews = new ReviewRepository($this->pdo);
        $albums = new CampAlbumService($audit, null);

        $message = new \Modules\InboundMail\Api\InboundMessage(
            id: 42,
            mailboxId: 2,
            consumerId: \Modules\Camps\Mail\CampsMessageConsumer::CONSUMER_ID,
            businessReference: \Modules\Camps\Mail\CampsMessageConsumer::UNSORTED_REFERENCE,
            linkOrigin: \Modules\InboundMail\Api\LinkOrigin::SENDER,
            subject: 'Confirmation de réservation',
            fromEmail: 'info@mozet.be',
            fromName: 'Domaine de Mozet',
            messageId: '<abc@mail>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-11-02 09:00:00'),
            bodyText: 'Nous confirmons du 12 au 19 juillet 2028. Prix : 2450 €.',
            bodyHtml: ''
        );

        $inbound = $this->createMock(\Modules\InboundMail\Api\InboundMailInterface::class);
        $inbound->method('findForReference')->willReturn([]);
        $inbound->method('findOneForReference')->willReturnCallback(
            static fn(string $c, string $r, int $id): ?\Modules\InboundMail\Api\InboundMessage
                => $id === 42 ? $message : null
        );
        $inbound->method('move')->willReturnCallback(
            function (string $c, string $from, string $to, int $id) use (&$moves): bool {
                $moves[] = ['from' => $from, 'to' => $to, 'message' => $id];

                return true;
            }
        );

        $campService = new CampService($this->camps, $audit, $this->places);
        $placeService = new PlaceService($this->places, $audit);
        $duplicates = new DuplicatePlaceDetector($this->places, null);

        return new CampsChiefController(
            TwigFactory::create(
                dirname(__DIR__, 4) . '/core/View/templates',
                false,
                ['camps' => dirname(__DIR__, 4) . '/modules/camps/views']
            ),
            $this->places,
            $this->camps,
            $placeService,
            $campService,
            new SectionDescriber($sections),
            $sections,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $audit,
            $settings,
            new ContactRepository($this->pdo, $encryption),
            new LinkRepository($this->pdo),
            new DocumentRepository($this->pdo),
            $albums,
            $reviews,
            new ReviewService($reviews, $audit, $this->places),
            $duplicates,
            new PlaceArchiveService($this->places, $this->camps, $audit),
            $inbound,
            null,
            null,
            null,
            new \Modules\Camps\Mail\StayFromMailService(
                $this->camps,
                $campService,
                $placeService,
                $duplicates,
                new \Modules\Camps\Mail\MessageReader(),
                $settings,
                $inbound
            )
        );
    }

    public function testTheCreationFormIsPreFilledFromAnUnsortedMessage(): void
    {
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $html = $controller->create(
            new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Domaine de Mozet', $html);
        $this->assertStringContainsString('2028-07-12', $html);
        $this->assertStringContainsString('2028-07-19', $html);
        $this->assertStringContainsString('name="message_id" value="42"', $html);
    }

    public function testAKnownPlaceIsSelectedRatherThanDescribedAgain(): void
    {
        $placeId = $this->places->create('Domaine de Mozet', null, null, 'Mozet', null, null);
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $html = (string) preg_replace('/\s+/', ' ', $controller->create(
            new Request('GET', '/chefs/camps/nouveau', ['message' => '42'], [], [], []),
            []
        )->getBody());

        $this->assertMatchesRegularExpression(
            '/value="' . $placeId . '" selected/',
            $html
        );
    }

    public function testTheMessageIsFiledUnderTheStayItCreated(): void
    {
        $moves = [];
        $controller = $this->controllerWithUnsortedMessage($moves);

        $response = $controller->store(
            new Request('POST', '/chefs/camps', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'place_id' => '0',
                'confirm_new' => '1',
                'place_name' => 'Domaine de Mozet',
                'stay_type' => 'grand_camp',
                'status' => 'to_confirm',
                'start_date' => '2028-07-12',
                'end_date' => '2028-07-19',
                'message_id' => '42',
            ], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $moves);
        $this->assertSame(\Modules\Camps\Mail\CampsMessageConsumer::UNSORTED_REFERENCE, $moves[0]['from']);
        $this->assertSame(42, $moves[0]['message']);
    }

    public function testTheStayPageSaysSoWhenNoMailWasFiledUnderIt(): void
    {
        $campId = $this->aStay();

        // inbound_mail is absent from this controller, which is exactly the
        // shape of a site that collects no mail — the section is still
        // there and says nothing arrived.
        $this->assertStringContainsString('Correspondance', $this->stayPage($campId));
        $this->assertStringContainsString('Aucun message rattaché', $this->stayPage($campId));
    }
}
