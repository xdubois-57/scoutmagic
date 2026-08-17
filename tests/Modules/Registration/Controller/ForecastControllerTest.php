<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\ForecastController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ForecastControllerTest extends TestCase
{
    private \PDO $pdo;
    private ForecastController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $currentYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);

        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new \Core\Import\MemberYearRepository($this->pdo));

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));

        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotService = new SlotService($this->pdo, $encryption, $settingService, $ageBracketRepository, $slotCapacityRepository, $requestRepository);
        $transferRepository = new SectionTransferRepository($this->pdo);
        $passageService = new PassageService($this->pdo, $encryption, $sectionService, $transferRepository, $requestRepository, $ageBracketRepository);
        $forecastService = new ForecastService($this->pdo, $encryption, $sectionService, $passageService);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/previsions');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new ForecastController($twig, $forecastService, $scoutYearResolver, $scoutYearService, $slotService);
    }

    public function testIndexRendersWithEmptyData(): void
    {
        $response = $this->controller->index(new Request('GET', '/previsions', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Prévisions', $response->getBody());
        $this->assertStringContainsString('2027-2028', $response->getBody()); // target year label
    }

    public function testIndexDistinguishesCertainFromHypothesis(): void
    {
        $response = $this->controller->index(new Request('GET', '/previsions', [], [], [], []), []);

        $body = $response->getBody();
        $this->assertStringContainsString('Certain', $body);
        $this->assertStringContainsString('Prévisionnel', $body);
    }

    public function testIndexShowsUnassignedLinkToPassage(): void
    {
        $response = $this->controller->index(new Request('GET', '/previsions', [], [], [], []), []);

        $this->assertStringContainsString('/passage', $response->getBody());
    }

    public function testIndexShowsRemainingCapacitySectionAboveUnassigned(): void
    {
        $response = $this->controller->index(new Request('GET', '/previsions', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertStringContainsString('Places restantes par année du parcours scout', $body);
        $capacityPos = strpos($body, 'Places restantes par année du parcours scout');
        $unassignedPos = strpos($body, 'Non attribués');
        $this->assertNotFalse($capacityPos);
        $this->assertNotFalse($unassignedPos);
        $this->assertLessThan($unassignedPos, $capacityPos);
    }

    /**
     * The variation has to be explainable from the cards next to it, so
     * "Fin de parcours" (members leaving the animés population by age —
     * last-year Pionniers, mostly) sits alongside "Départs annoncés"
     * rather than being an unexplained hole in the total.
     */
    public function testIndexShowsBothDepartureAndEndOfJourneyFigures(): void
    {
        $body = $this->controller->index(new Request('GET', '/previsions', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Départs annoncés', $body);
        $this->assertStringContainsString('Fin de parcours', $body);
    }
}
