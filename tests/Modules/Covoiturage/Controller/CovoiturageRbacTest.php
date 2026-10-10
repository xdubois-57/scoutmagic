<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Covoiturage\Controller\CarpoolController;
use Modules\Covoiturage\Controller\CarpoolOrganizerController;
use Modules\Covoiturage\Repository\CarpoolRepository;
use Modules\Covoiturage\Repository\OfferRepository;
use Modules\Covoiturage\Repository\SeatRequest;
use Modules\Covoiturage\Repository\SeatRequestRepository;
use Modules\Covoiturage\Service\CarpoolBoard;
use Modules\Covoiturage\Service\CarpoolService;
use Modules\Covoiturage\Service\CarpoolViewerResolver;
use Modules\Covoiturage\Service\DeparturePlanner;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Service\OfferService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Covoiturage\CovoiturageTestHelper as H;
use Tests\Modules\Covoiturage\FakeCalendar;
use Tests\TestTwig;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Every route of the module, through the REAL Router/RbacGuard pipeline, at
 * the role_min module.json declares for it — allowed at that floor, refused
 * one level below. The GET pages are rendered for real at the floor, so a
 * template that breaks fails here too.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CovoiturageRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private CarpoolController $members;
    private CarpoolOrganizerController $organizer;
    private int $accountId;
    private int $carpoolId;
    private int $offerId;
    private int $requestId;
    private CarpoolRepository $carpools;
    private CarpoolBoard $board;
    private SectionService $sections;
    private CarpoolViewerResolver $viewers;
    private CarpoolService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $encryption = H::encryption();

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('parent@test.be', 'user_accounts.email'), $encryption->blindIndex('parent@test.be', 'email')]);
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)");

        $this->carpoolId = H::carpool($this->pdo, 10, 11);
        // Its creator: past the route's floor, editing is narrowed further
        // to whoever may organise THIS carpool (Service\CarpoolViewer).
        $this->pdo->exec('UPDATE carpools SET created_by_user_account_id = ' . $this->accountId);
        $this->offerId = H::offer($this->pdo, $this->carpoolId, $this->accountId);
        $this->requestId = H::request($this->pdo, $this->offerId, 99, ['Tom Leroy']);

        $root = dirname(__DIR__, 4);
        $twig = TestTwig::create(['covoiturage' => $root . '/modules/covoiturage/views'], ['param' => static fn(string $key): string => 'Test Unit']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $this->twig = $twig;

        $settings = new SettingService(new SettingRepository($this->pdo));
        $carpools = new CarpoolRepository($this->pdo);
        $offers = new OfferRepository($this->pdo, $encryption);
        $requests = new SeatRequestRepository($this->pdo, $encryption);
        $sections = H::sections($this->pdo);
        $board = new CarpoolBoard($carpools, $offers, $requests, $settings, $sections);
        $viewers = new CarpoolViewerResolver(new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            $settings,
            new MemberYearRepository($this->pdo)
        ));

        $this->members = new CarpoolController(
            $twig,
            $carpools,
            $offers,
            $requests,
            $board,
            new OfferService($offers, $requests, $this->pdo),
            $viewers,
            // #703: one linked event with hours, no geocoding (so the
            // route is unknown and the 30-minute rule applies).
            new DeparturePlanner(new FakeCalendar([
                new EventSummary(
                    701,
                    'Fête',
                    'Louveteaux',
                    H::day(10),
                    H::day(11),
                    startTime: '14:00',
                    endTime: '17:00'
                ),
            ])),
            'Local des Louveteaux, rue du Parc 1, Wavre'
        );
        $this->carpools = $carpools;
        $this->board = $board;
        $this->sections = $sections;
        $this->viewers = $viewers;
        $this->service = new CarpoolService($carpools, $offers, $sections, H::members($this->pdo), new FakeCalendar([]));
        $this->organizer = new CarpoolOrganizerController(
            $twig,
            $carpools,
            $this->service,
            $board,
            $sections,
            $viewers
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * Every route module.json declares, with the role one level below its
     * floor.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/covoiturage/module.json'),
            true
        );
        $below = ['identified' => 'public', 'chief' => 'intendant'];
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            $cases[$route['method'] . ' ' . $route['path']] = [
                $route['method'],
                $route['path'],
                $route['action'],
                $route['role_min'],
                $below[$route['role_min']],
            ];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testTheDeclaredFloorGetsThrough(string $method, string $path, string $action, string $floor, string $below): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', $floor);

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "{$floor} refused on {$method} {$path}");
        if ($method === 'GET') {
            $this->assertLessThan(400, $response->getStatusCode(), "{$method} {$path}: " . substr($response->getBody(), 0, 400));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testOneLevelBelowIsRefused(string $method, string $path, string $action, string $floor, string $below): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', $below);

        $response = $this->frontController($method, $path, $action, $floor)
            ->handle(new Request($method, $this->resolve($path), [], [], [], []));

        $this->assertContains($response->getStatusCode(), [302, 403], "{$below} reached {$method} {$path}");
        $this->assertNotSame(200, $response->getStatusCode());
    }

    /**
     * #703 on the offer form: the meeting point starts at the unit's
     * premises, the hour at the event's start minus 30 minutes (no route
     * here), with the sentence saying how; the return field shows only
     * once its box is ticked, and its hour is the event's end.
     */
    public function testTheOfferFormSuggestsFromTheLinkedEvents(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);
        $id = H::carpool($this->pdo, 10, 11, [new CarpoolEvent(701, 'Fête', null, null)]);

        $form = $this->members->offerForm(
            new Request('GET', '/covoiturage/' . $id . '/proposer', [], [], [], []),
            ['id' => (string) $id]
        )->getBody();

        $this->assertSame('Local des Louveteaux, rue du Parc 1, Wavre', self::valueOf($form, 'offer-endpoint'));
        $this->assertSame('13:30', self::valueOf($form, 'offer-time'));
        $this->assertStringContainsString(
            'Heure suggérée : 13 h 30 — début à 14 h 00, trajet non calculé : 30 min avant.',
            $form
        );
        $this->assertMatchesRegularExpression('~<div id="offer-return-block"\s+hidden>~', $form);
        $this->assertStringNotContainsString('Seulement si vous cochez la case ci-dessus.', $form);
        $this->assertSame('17:00', self::valueOf($form, 'offer-return-time'));
        $this->assertStringContainsString('Arrivée non estimée : trajet non calculé.', $form);
        $this->assertStringContainsString('data-travel-url="/covoiturage/' . $id . '/trajet"', $form);

        // On the return, the meeting point is not pre-filled: the premises
        // are where the outbound starts, not where the return ends.
        $return = $this->members->offerForm(
            new Request('GET', '/covoiturage/' . $id . '/proposer', ['sens' => 'return'], [], [], []),
            ['id' => (string) $id]
        )->getBody();
        $this->assertStringNotContainsString('value="Local des Louveteaux, rue du Parc 1, Wavre"', $return);
        $this->assertSame('17:00', self::valueOf($return, 'offer-time'));
    }

    /** The value attribute of the input with that id. */
    private static function valueOf(string $html, string $id): ?string
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $input = $document->getElementById($id);

        return $input?->getAttribute('value');
    }

    /** The route the form asks: JSON, and the fallback when nothing can be measured. */
    public function testTheTravelRouteAnswersTheFallbackWithoutAnError(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);
        $id = H::carpool($this->pdo, 10, 11, [new CarpoolEvent(701, 'Fête', null, null)]);

        $response = $this->members->travel(
            new Request('GET', '/covoiturage/' . $id . '/trajet', ['depuis' => 'Gare de Wavre'], [], [], []),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertNull($data['minutes']);
        $this->assertSame('13:30', $data['outbound']['time']);
        $this->assertSame('17:00', $data['return']['time']);
        $this->assertNull($data['return']['arrival']);
    }

    public function testThePagesSayWhatTheMaquetteSays(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);

        $list = $this->frontController('GET', '/covoiturage', 'index', 'identified')
            ->handle(new Request('GET', '/covoiturage', [], [], [], []))->getBody();
        $this->assertStringContainsString('Covoiturages passés', $list);
        $this->assertStringContainsString('Trajet libre', $list);

        $page = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId, [], [], [], []))->getBody();
        $this->assertStringContainsString('Sophie Martin (vous)', $page);
        $this->assertStringContainsString('Demandes (1)', $page);
        $this->assertStringContainsString('Accepter la place', $page);
        // Pending, and the driver already has the family's phone, so they
        // can call before answering (#703). The whole number, not a
        // prefix: four hex digits of the page's CSRF token once read 0495.
        $this->assertStringContainsString('0495 88 77 66', $page);
        // The address says what it is on this trip, and opens a route.
        $this->assertStringContainsString('Destination :', $page);
        $this->assertStringContainsString('title="Itinéraire depuis votre position"', $page);
        // Refusing asks for an optional word, 200 characters at most.
        $this->assertStringContainsString('data-confirm-note="Un mot pour la famille (facultatif)"', $page);
        $this->assertStringContainsString('data-confirm-note-maxlength="200"', $page);

        // On the return, the same address is where every car leaves from.
        $return = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId, ['sens' => 'return'], [], [], []))
            ->getBody();
        $this->assertStringContainsString('Départ :', $return);
        $this->assertStringNotContainsString('Destination :', $return);

        $form = $this->frontController('GET', '/covoiturage/{id}/proposer', 'offerForm', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId . '/proposer', [], [], [], []))->getBody();
        $this->assertStringContainsString('Un point de rendez-vous, pas votre adresse', $form);
        $this->assertStringContainsString('Fixé par le covoiturage', $form);
    }

    public function testTheListBadgesMyPartAndTheDayBannerAppearsOnTheDay(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);

        // setUp: the account drives a car of a carpool 10 days away.
        $list = $this->frontController('GET', '/covoiturage', 'index', 'identified')
            ->handle(new Request('GET', '/covoiturage', [], [], [], []))->getBody();
        $this->assertStringContainsString('badge text-bg-info">Aller · Conducteur<', $list);

        $before = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId, [], [], [], []))->getBody();
        $this->assertStringNotContainsString('data-carpool-day-banner', $before);

        // The same driver, a carpool that leaves today, a family already asking.
        $today = H::carpool($this->pdo, 0);
        $car = H::offer($this->pdo, $today, $this->accountId);
        H::request($this->pdo, $car, 99, ['Tom Leroy'], SeatRequest::ACCEPTED);

        $page = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $today, [], [], [], []))->getBody();
        $this->assertStringContainsString('data-carpool-day-banner', $page);
        $this->assertStringContainsString('Vous conduisez.', $page);
        $this->assertStringContainsString('Rendez-vous : Parking des locaux', $page);
        $this->assertStringContainsString('Tom Leroy', $page);
        $this->assertStringContainsString('0495 88 77 66', $page);
        // The banner reuses the page's own route link: one per address, none new.
        $this->assertStringContainsString('Itinéraire vers Gîte de Han-sur-Lesse', $page);
    }

    public function testAnAcceptedFamilySeesTheDriversNumberInTheBannerAndAPendingOneDoesNot(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);
        $today = H::carpool($this->pdo, 0);
        $car = H::offer($this->pdo, $today, 99);
        $request = H::request($this->pdo, $car, $this->accountId, ['Léa'], SeatRequest::PENDING);
        $url = '/covoiturage/' . $today;
        $show = fn(): string => $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', $url, [], [], [], []))->getBody();

        $pending = $show();
        $this->assertStringContainsString('alert alert-warning', $pending);
        $this->assertStringContainsString('toujours à confirmer', $pending);
        $this->assertStringNotContainsString('0478 12 34 56', $pending);

        (new SeatRequestRepository($this->pdo, H::encryption()))->transition($request, SeatRequest::PENDING, SeatRequest::ACCEPTED);
        $accepted = $show();
        $this->assertStringContainsString('Votre place est confirmée</strong> pour Léa', $accepted);
        $this->assertStringContainsString('Conducteur : Sophie Martin', $accepted);
        $this->assertStringContainsString('0478 12 34 56', $accepted);
    }

    /** #790: taking a granted seat back asks for the same optional word as a refusal. */
    public function testRetirerLaPlaceAsksForTheSameOptionalWordAsRefuser(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::IDENTIFIED->value);
        (new SeatRequestRepository($this->pdo, H::encryption()))
            ->transition($this->requestId, SeatRequest::PENDING, SeatRequest::ACCEPTED);

        $page = $this->frontController('GET', '/covoiturage/{id}', 'show', 'identified')
            ->handle(new Request('GET', '/covoiturage/' . $this->carpoolId, [], [], [], []))->getBody();

        $this->assertSame(1, preg_match('/<form[^>]*retirer-la-place[^>]*>/s', $page, $form));
        $this->assertStringContainsString('data-confirm-note="Un mot pour la famille (facultatif)"', $form[0]);
        $this->assertStringContainsString('data-confirm-note-name="message"', $form[0]);
        $this->assertStringContainsString('data-confirm-note-maxlength="200"', $form[0]);
    }

    public function testAChiefOfAnotherSectionCannotEditACarpoolTheyDoNotOrganize(): void
    {
        // Past the route's floor, the controller narrows to whoever may
        // organise THIS carpool: its creator, the staff it concerns, the
        // Staff d'U.
        $this->pdo->exec('UPDATE carpools SET created_by_user_account_id = NULL');
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);

        $response = $this->frontController('GET', '/covoiturage/organiser/{id}/modifier', 'edit', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], [], [], []));
        $this->assertSame(403, $response->getStatusCode());

        AuthSession::login($this->accountId, 'parent@test.be', Role::ADMIN->value);
        $response = $this->frontController('GET', '/covoiturage/organiser/{id}/modifier', 'edit', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], [], [], []));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTheAddressLookupAnswersWithThePointOfTheAddress(): void
    {
        // #642: the map's live lookup, with Nominatim replaced by a fake.
        $this->organizer = $this->organizerWithLocator(['latitude' => 50.125, 'longitude' => 5.187]);
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);

        $response = $this->frontController('GET', '/covoiturage/organiser/adresse', 'locateAddress', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/adresse', ['q' => 'Gîte de Han, rue des Grottes 12'], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['success' => true, 'found' => true, 'latitude' => 50.125, 'longitude' => 5.187],
            json_decode($response->getBody(), true)
        );

        $form = $this->frontController('GET', '/covoiturage/organiser/nouveau', 'create', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/nouveau', [], [], [], []))->getBody();
        $this->assertStringContainsString('data-locate-url="/covoiturage/organiser/adresse"', $form);
        $this->assertStringContainsString('name="point_automatic"', $form);
    }

    public function testWithTheLookupSwitchedOffTheRouteFindsNothingAndTheFormNeverAsks(): void
    {
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);

        $response = $this->frontController('GET', '/covoiturage/organiser/adresse', 'locateAddress', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/adresse', ['q' => 'Gîte de Han, rue des Grottes 12'], [], [], []));
        $this->assertSame(
            ['success' => true, 'found' => false, 'reason' => 'unavailable'],
            json_decode($response->getBody(), true)
        );

        $form = $this->frontController('GET', '/covoiturage/organiser/nouveau', 'create', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/nouveau', [], [], [], []))->getBody();
        $this->assertStringNotContainsString('data-locate-url', $form);
    }

    /**
     * Issue #692: a new carpool refused for its dates came back with its
     * chip reading « Évènement n° 494 » — the form posts ids only, and the
     * titles were known for a saved carpool alone.
     */
    public function testARefusedNewCarpoolShowsItsEventsByTitle(): void
    {
        $this->useCalendarWith(new \Modules\Calendar\Api\EventSummary(494, 'Week-end de rentrée', 'Louveteaux', '2020-10-10', '2020-10-12', null, 'Gîte de Han', null, 'Louveteaux'));
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);
        $body = [
            '_csrf_token' => \Core\Security\CsrfGuard::generateToken(),
            'event_ids' => ['494'],
            'address' => 'Gîte de Han',
            'outbound_date' => '2020-10-10',
            'return_date' => '',
        ];

        $page = $this->frontController('POST', '/covoiturage/organiser/nouveau', 'store', 'chief')
            ->handle(new Request('POST', '/covoiturage/organiser/nouveau', [], $body, [], []))
            ->getBody();

        $this->assertStringContainsString('est déjà passée', $page);
        // The chips are drawn from the picker's data-selected, not from
        // the page's text (which also lists the event among the options).
        $this->assertSame(1, preg_match('/data-selected="([^"]*)"/', $page, $match));
        $chips = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        $this->assertSame(
            [['id' => 494, 'label' => 'Week-end de rentrée', 'badge' => 'Louveteaux']],
            $chips
        );
    }

    /** Issue #692: the places route carries the events' dates too. */
    public function testThePlacesRouteAlsoAnswersTheEventsDates(): void
    {
        $this->useCalendarWith(
            new \Modules\Calendar\Api\EventSummary(494, 'Week-end', 'Louveteaux', '2027-10-10', '2027-10-12', null, 'Gîte de Han'),
            new \Modules\Calendar\Api\EventSummary(495, 'Week-end', 'Baladins', '2027-10-09', '2027-10-11', null, 'Gîte de Han')
        );
        AuthSession::login($this->accountId, 'parent@test.be', Role::CHIEF->value);

        $response = $this->frontController('GET', '/covoiturage/organiser/lieux', 'eventLocations', 'chief')
            ->handle(new Request('GET', '/covoiturage/organiser/lieux', ['ids' => '494,495'], [], [], []));

        $this->assertSame(
            ['success' => true, 'locations' => ['Gîte de Han'], 'dates' => ['outbound' => '2027-10-09', 'return' => '2027-10-12']],
            json_decode($response->getBody(), true)
        );
    }

    public function testAPointRemovedByHandStaysRemovedWhenTheFormComesBackRefused(): void
    {
        // « Retirer » posts empty coordinates and point_manual=1; a refusal
        // for another reason (here, the dates) must show the form with the
        // point still marked as a human's decision, or the page's lookup
        // would put the pin straight back on the address.
        AuthSession::login($this->accountId, 'parent@test.be', Role::ADMIN->value);
        $body = [
            '_csrf_token' => \Core\Security\CsrfGuard::generateToken(),
            'address' => 'Gîte de Han, rue des Grottes 12',
            'outbound_date' => '2027-05-10',
            'return_date' => '2027-05-01',
            'latitude' => '',
            'longitude' => '',
            'point_automatic' => '0',
            'point_manual' => '1',
        ];

        $page = $this->frontController('POST', '/covoiturage/organiser/{id}/modifier', 'update', 'chief')
            ->handle(new Request('POST', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], $body, [], []))
            ->getBody();

        $this->assertStringContainsString('Le retour ne peut pas précéder', $page);
        $this->assertStringContainsString('data-manual="1"', $page);
        $this->assertStringContainsString('name="point_manual" value="1"', $page);
    }

    public function testAnAutomaticPointPostedBackWithoutTheScriptIsNotCalledHandPlaced(): void
    {
        // Without JavaScript the edit form posts the carpool's own point
        // back untouched, and `point_automatic` stays at 0: that is not a
        // human's point, even when the form comes back refused.
        (new \Modules\Covoiturage\Repository\CarpoolRepository($this->pdo))->points()
            ->recordGeocoding($this->carpoolId, new \Core\Geo\GeoPoint(50.125, 5.187), new \DateTimeImmutable());
        AuthSession::login($this->accountId, 'parent@test.be', Role::ADMIN->value);
        $body = [
            '_csrf_token' => \Core\Security\CsrfGuard::generateToken(),
            'address' => 'Gîte de Han-sur-Lesse, rue des Grottes 12',
            'outbound_date' => '2027-05-10',
            'return_date' => '2027-05-01',
            'latitude' => '50.125000',
            'longitude' => '5.187000',
            'point_automatic' => '0',
        ];

        $page = $this->frontController('POST', '/covoiturage/organiser/{id}/modifier', 'update', 'chief')
            ->handle(new Request('POST', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], $body, [], []))
            ->getBody();

        $this->assertStringContainsString('Le retour ne peut pas précéder', $page);
        $this->assertStringContainsString('data-manual="0"', $page);

        // Coordinates typed over it are a human's, as before.
        $body['latitude'] = '50.200000';
        $page = $this->frontController('POST', '/covoiturage/organiser/{id}/modifier', 'update', 'chief')
            ->handle(new Request('POST', '/covoiturage/organiser/' . $this->carpoolId . '/modifier', [], $body, [], []))
            ->getBody();
        $this->assertStringContainsString('data-manual="1"', $page);
    }

    /** @param array{latitude: float, longitude: float}|null $answer */
    private function organizerWithLocator(?array $answer): CarpoolOrganizerController
    {
        $geocoder = new class ($answer) extends \Core\Geo\GeocodingService {
            /** @param array{latitude: float, longitude: float}|null $answer */
            public function __construct(private ?array $answer)
            {
                parent::__construct('https://unit.test');
            }

            public function geocodeLine(?string $line): ?array
            {
                return $this->answer;
            }
        };

        return new CarpoolOrganizerController(
            $this->twig,
            $this->carpools,
            $this->service,
            $this->board,
            $this->sections,
            $this->viewers,
            new \Core\Geo\AddressLocator($this->pdo, $geocoder, static function (int $microseconds): void {
            })
        );
    }

    private function resolve(string $path): string
    {
        $id = match (true) {
            str_starts_with($path, '/covoiturage/voitures/') => $this->offerId,
            str_starts_with($path, '/covoiturage/demandes/') => $this->requestId,
            default => $this->carpoolId,
        };

        return str_replace('{id}', (string) $id, $path);
    }

    private function useCalendarWith(\Modules\Calendar\Api\EventSummary ...$events): void
    {
        $this->organizer = new CarpoolOrganizerController(
            $this->twig,
            $this->carpools,
            new CarpoolService(
                $this->carpools,
                new OfferRepository($this->pdo, H::encryption()),
                $this->sections,
                H::members($this->pdo),
                new FakeCalendar(array_values($events))
            ),
            $this->board,
            $this->sections,
            $this->viewers
        );
    }

    private function frontController(string $method, string $path, string $action, string $floor): FrontController
    {
        $class = str_starts_with($path, '/covoiturage/organiser') ? CarpoolOrganizerController::class : CarpoolController::class;
        $router = new Router();
        $router->addRoute($method, $path, $class, $action, $floor);

        $configFile = sys_get_temp_dir() . '/test_covoiturage_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig, new AppConfig($configFile));
        $front->registerController($class, $class === CarpoolController::class ? $this->members : $this->organizer);

        return $front;
    }
}
