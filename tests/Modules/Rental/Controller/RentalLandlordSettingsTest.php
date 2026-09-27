<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Controller\RentalPricingController;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Core\Member\Repository\MemberProfileRepository;

/**
 * The « Bailleur » section of an asset's settings (issue #497): who the
 * contract names as landlord for THIS asset, when it is not the module's.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalLandlordSettingsTest extends TestCase
{
    private \PDO $pdo;
    private \Twig\Environment $twig;
    private RentalPricingController $controller;
    private RentalAssetRepository $assetRepository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $scoutYearId = $scoutYearService->getCurrentYear()['id'];
        $scoutYearResolver = new ScoutYearResolver(
            $scoutYearService,
            $settingService,
            new MemberYearRepository($this->pdo)
        );

        $assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $managerRepository = new RentalAssetManagerRepository($this->pdo);
        $memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $encryption)
);


        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        $this->twig->addGlobal('site_name', 'Unité Test');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new RentalPricingController(
            $this->twig,
            new RentalPricingService(
                new RentalPricingRepository($this->pdo),
                new RentalPricingEngine(),
                new JournalService(new JournalRepository($this->pdo))
            ),
            new RentalAvailabilityService(new AvailabilityCalculator(), new RentalConstraintsRepository($this->pdo), []),
            new RentalAuthorizationService($memberService, $assetRepository, $managerRepository),
            $assetRepository,
            $scoutYearResolver,
            // Required since IT-02, versioned since issue #494: the
            // conditions a renter ticks. Nothing here reaches them, but a
            // controller that cannot be built proves nothing.
            new \Modules\Rental\Service\RentalConditionsService(
                new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo),
                new EditableContentService(new EditableContentRepository($this->pdo))
            ),
        );
        $this->assetRepository = $assetRepository;

        $this->assetId = $assetRepository->create('Local', 'Local Saint-Georges', 'local-saint-georges', 60, 1, null, null, null, true);

        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MGR');
        RentalTestHelper::insertMemberYear($this->pdo, $encryption, $memberId, $scoutYearId, 'manager@test.be');
        $managerRepository->grant($this->assetId, $memberId, false);
        AuthSession::login(1, 'manager@test.be', 'identified');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
    }

    /**
     * @param array<string, string> $body
     */
    private function save(array $body, string $slug = 'local-saint-georges'): Response
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $_POST = $body;

        $router = new Router();
        $router->addRoute(
            'POST',
            '/mes-locations/{slug}/reglages/bailleur',
            RentalPricingController::class,
            'saveLandlord',
            'identified'
        );

        $configFile = sys_get_temp_dir() . '/test_rental_landlord_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(RentalPricingController::class, $this->controller);
        $response = $frontController->handle(
            new Request('POST', '/mes-locations/' . $slug . '/reglages/bailleur', [], $body, [], [])
        );

        $_POST = [];
        @unlink($configFile);

        return $response;
    }

    private function asset(): \Modules\Rental\Repository\RentalAsset
    {
        $asset = $this->assetRepository->findById($this->assetId);
        $this->assertNotNull($asset);

        return $asset;
    }

    public function testTheLandlordIsStoredWholeAndTheManagerLandsBackOnTheSection(): void
    {
        $response = $this->save([
            'landlord_name' => '  ASBL Les Amis du Local ',
            'landlord_address' => "Place du Parc 3\n1300 Wavre",
            'landlord_enterprise_number' => 'BE 0123.456.789',
        ]);

        $this->assertSame('/mes-locations/local-saint-georges/reglages#bailleur', $response->getHeaders()['Location']);
        $asset = $this->asset();
        $this->assertSame('ASBL Les Amis du Local', $asset->landlordName);
        $this->assertSame("Place du Parc 3\n1300 Wavre", $asset->landlordAddress);
        $this->assertSame('BE 0123.456.789', $asset->landlordEnterpriseNumber);
    }

    /** Three empty fields hand the asset back: that is the undo. */
    public function testEmptyFieldsHandTheAssetBackToTheModule(): void
    {
        $this->assetRepository->saveLandlord($this->assetId, 'ASBL', 'Rue 1', '0123456789');

        $this->save(['landlord_name' => '', 'landlord_address' => ' ', 'landlord_enterprise_number' => '']);

        $asset = $this->asset();
        $this->assertNull($asset->landlordName);
        $this->assertNull($asset->landlordAddress);
        $this->assertNull($asset->landlordEnterpriseNumber);
        $this->assertStringContainsString('bailleur des réglages du module', (string) (FlashMessage::get()['message'] ?? ''));
    }

    public function testAMalformedEnterpriseNumberIsRefusedAndNothingIsStored(): void
    {
        $this->save([
            'landlord_name' => 'ASBL Les Amis du Local',
            'landlord_address' => 'Place du Parc 3',
            'landlord_enterprise_number' => '12345',
        ]);

        $this->assertNull($this->asset()->landlordName, 'a refusal stores none of the three');
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString("numéro d'entreprise", (string) ($flash['message'] ?? ''));
    }

    /** A number alone names nobody, and would leave the unit's name over it. */
    public function testAnEnterpriseNumberAloneIsRefused(): void
    {
        $this->save(['landlord_name' => '', 'landlord_address' => '', 'landlord_enterprise_number' => '0123.456.789']);

        $this->assertNull($this->asset()->landlordEnterpriseNumber);
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    public function testAnAssetTheVisitorDoesNotManageIsNotFound(): void
    {
        AuthSession::logout();
        AuthSession::login(2, 'stranger@test.be', 'identified');

        $response = $this->save(['landlord_name' => 'ASBL', 'landlord_address' => 'Rue 1']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->asset()->landlordName);
    }
}
