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

        $this->assertSame(302, $response->getStatusCode());
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
}
