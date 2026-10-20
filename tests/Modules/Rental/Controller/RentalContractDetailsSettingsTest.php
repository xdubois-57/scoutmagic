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
 * The « Contrat » section of an asset's settings (issue #977): the asset's
 * address and its free-withdrawal deadline, which annex 8.1 of the Walloon
 * tourism code requires a contract to state.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalContractDetailsSettingsTest extends TestCase
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
            '/mes-locations/{slug}/reglages/contrat',
            RentalPricingController::class,
            'saveContractDetails',
            'identified'
        );

        $configFile = sys_get_temp_dir() . '/test_rental_contract_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(RentalPricingController::class, $this->controller);
        $response = $frontController->handle(
            new Request('POST', '/mes-locations/' . $slug . '/reglages/contrat', [], $body, [], [])
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

    public function testTheAddressAndTheDeadlineAreStoredAndTheManagerLandsBackOnTheSection(): void
    {
        $response = $this->save(['asset_address' => "  Chemin du Camp 4\n1300 Wavre ", 'free_withdrawal_days' => '45']);

        $this->assertSame('/mes-locations/local-saint-georges/reglages#contrat', $response->getHeaders()['Location']);
        $asset = $this->asset();
        $this->assertSame("Chemin du Camp 4\n1300 Wavre", $asset->address);
        $this->assertSame(45, $asset->freeWithdrawalDays);
        $this->assertSame('2027-05-17', $asset->freeWithdrawalDeadline('2027-07-01'));
        $this->assertSame('Réglages du contrat enregistrés.', FlashMessage::get()['message'] ?? null);
    }

    /** A fresh asset carries the schema's default deadline and no address. */
    public function testAFreshAssetHasNoAddressAndTheDefaultDeadline(): void
    {
        $asset = $this->asset();

        $this->assertNull($asset->address);
        $this->assertSame(90, $asset->freeWithdrawalDays);
    }

    /** Emptying the address is allowed, and the manager is told what it costs. */
    public function testAnEmptiedAddressIsStoredAsNoneAndSaysNoContractCanBeGenerated(): void
    {
        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4', 60);

        $this->save(['asset_address' => '  ', 'free_withdrawal_days' => '60']);

        $this->assertNull($this->asset()->address);
        $this->assertStringContainsString('aucun contrat ne peut être généré', (string) (FlashMessage::get()['message'] ?? ''));
    }

    public function testADeadlineThatIsNotAWholeNumberOfDaysIsRefusedAndNothingIsStored(): void
    {
        foreach (['', '-3', '12.5', 'trois', '731'] as $days) {
            $this->save(['asset_address' => 'Chemin du Camp 4', 'free_withdrawal_days' => $days]);

            $flash = FlashMessage::get();
            $this->assertSame('error', $flash['type'] ?? null, $days);
            $this->assertStringContainsString('jours avant l\'arrivée', (string) ($flash['message'] ?? ''));
            $this->assertNull($this->asset()->address, 'a refusal stores nothing');
        }
    }

    public function testTheSettingsCardShowsWhatIsInForce(): void
    {
        $this->assetRepository->saveContractDetails($this->assetId, 'Chemin du Camp 4', 1);
        $html = $this->twig->render('@rental/management/_contract_details.html.twig', ['asset' => $this->asset()]);

        $this->assertStringContainsString('Chemin du Camp 4', $html);
        $this->assertStringContainsString('1 jour avant l\'arrivée', $html);
        $this->assertStringContainsString('action="/mes-locations/local-saint-georges/reglages/contrat"', $html);

        $this->assetRepository->saveContractDetails($this->assetId, null, 30);
        $html = $this->twig->render('@rental/management/_contract_details.html.twig', ['asset' => $this->asset()]);
        $this->assertStringContainsString('aucun contrat ne peut être généré', $html);
        $this->assertStringContainsString('30 jours avant l\'arrivée', $html);
    }

    public function testAnAssetTheVisitorDoesNotManageIsNotFound(): void
    {
        AuthSession::logout();
        AuthSession::login(2, 'stranger@test.be', 'identified');

        $response = $this->save(['asset_address' => 'Chemin du Camp 4', 'free_withdrawal_days' => '30']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->asset()->address);
    }
}
