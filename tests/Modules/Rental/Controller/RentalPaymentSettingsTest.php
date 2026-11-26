<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Controller\RentalPricingController;
use Modules\Rental\Payment\DepositMode;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The « Paiements » section saves three things independently (issue #1355,
 * D11): the tracking switch, the deposit with its deadlines, and the
 * security deposit — each keeping everything else, the account and the
 * category included. The deposit and the security deposit stay settable
 * without Finance (D12).
 */
#[Group('database')]
final class RentalPaymentSettingsTest extends TestCase
{
    private \PDO $pdo;
    private RentalPaymentRepository $payments;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->payments = new RentalPaymentRepository($this->pdo, $encryption);

        $scoutYearService = new ScoutYearService($this->pdo);
        $assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->assetId = $assetRepository->create('Local', 'Local', 'local', null, 1, null, null, null, true);

        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MGR');
        RentalTestHelper::insertMemberYear(
            $this->pdo,
            $encryption,
            $memberId,
            (int) $scoutYearService->getCurrentYear()['id'],
            'manager@test.be'
        );
        $managerRepository->grant($this->assetId, $memberId, false);
        AuthSession::login(1, 'manager@test.be', 'identified');

        $this->assetRepository = $assetRepository;
        $this->managerRepository = $managerRepository;
        $this->encryption = $encryption;
    }

    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private EncryptionService $encryption;

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
    }

    private function controller(bool $withFinance): RentalPricingController
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $settingService = new SettingService(new SettingRepository($this->pdo));

        return new RentalPricingController(
            TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false, [
                'rental' => dirname(__DIR__, 4) . '/modules/rental/views',
            ]),
            new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal),
            new RentalAvailabilityService(new AvailabilityCalculator(), new RentalConstraintsRepository($this->pdo), []),
            new RentalAuthorizationService(
                new MemberService(
                    new MemberYearRepository($this->pdo),
                    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
                ),
                $this->assetRepository,
                $this->managerRepository
            ),
            $this->assetRepository,
            new ScoutYearResolver(new ScoutYearService($this->pdo), $settingService, new MemberYearRepository($this->pdo)),
            new \Modules\Rental\Service\RentalConditionsService(
                new \Modules\Rental\Repository\RentalConditionsVersionRepository($this->pdo),
                new EditableContentService(new EditableContentRepository($this->pdo))
            ),
            new RentalPaymentService(
                $this->payments,
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
                $journal,
                $withFinance ? $this->createStub(\Modules\Finance\Api\ExpectedReceivableInterface::class) : null,
                $withFinance ? $this->createStub(\Modules\Finance\Api\StructuredCommunicationInterface::class) : null
            )
        );
    }

    /** A configuration with every field set, the account and the category pinned. */
    private function storeEverything(bool $enabled = true): void
    {
        $this->payments->saveSettings($this->assetId, new PaymentSettings(
            enabled: $enabled,
            financeAccountId: 3,
            depositMode: DepositMode::PERCENTAGE,
            depositPercentage: 30,
            depositDueDays: 10,
            balanceDueDays: 30,
            securityDepositEnabled: true,
            securityDepositAmountCents: 30000,
            financeCategoryId: 4
        ));
    }

    /** @param array<string, string> $body */
    private function post(RentalPricingController $controller, string $action, array $body): Response
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $_POST = $body;

        return $controller->{$action}(
            new Request('POST', '/mes-locations/local/reglages/x', [], $body, [], []),
            ['slug' => 'local']
        );
    }

    /** @param array<string, mixed> $payload */
    private function postJson(RentalPricingController $controller, array $payload): Response
    {
        $payload['_csrf_token'] = CsrfGuard::generateToken();
        $_POST = [];

        $request = new \Tests\RequestWithInput(
            'POST',
            '/mes-locations/local/reglages/suivi-paiements',
            [],
            [],
            [],
            [],
            (string) json_encode($payload)
        );

        return $controller->saveTracking($request, ['slug' => 'local']);
    }

    /** @return array<string, mixed> */
    private static function decode(Response $response): array
    {
        $data = json_decode($response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }

    public function testTheDepositSavesItsTermsAndNothingElse(): void
    {
        $this->storeEverything();

        $response = $this->post($this->controller(true), 'saveDeposit', [
            'deposit_mode' => 'fixed',
            'deposit_amount' => '150,00',
            'deposit_due_days' => '7',
            'balance_due_days' => '21',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $stored = $this->payments->loadSettings($this->assetId);
        $this->assertSame(DepositMode::FIXED, $stored->depositMode);
        $this->assertSame(15000, $stored->depositAmountCents);
        $this->assertSame(7, $stored->depositDueDays);
        $this->assertSame(21, $stored->balanceDueDays);
        // Everything else as it was.
        $this->assertTrue($stored->enabled);
        $this->assertTrue($stored->securityDepositEnabled);
        $this->assertSame(30000, $stored->securityDepositAmountCents);
        $this->assertSame(3, $stored->financeAccountId);
        $this->assertSame(4, $stored->financeCategoryId);
    }

    public function testTheSecurityDepositSavesItselfAndNothingElse(): void
    {
        $this->storeEverything();

        $this->post($this->controller(true), 'saveSecurityDeposit', [
            'security_deposit_enabled' => 'on',
            'security_deposit_amount' => '250,00',
        ]);

        $stored = $this->payments->loadSettings($this->assetId);
        $this->assertSame(25000, $stored->securityDepositAmountCents);
        $this->assertSame(DepositMode::PERCENTAGE, $stored->depositMode);
        $this->assertSame(30, $stored->depositPercentage);
        $this->assertSame(30, $stored->balanceDueDays);
        $this->assertTrue($stored->enabled);
        $this->assertSame(3, $stored->financeAccountId);
        $this->assertSame(4, $stored->financeCategoryId);
    }

    public function testASecurityDepositAskedWithNoAmountIsRefused(): void
    {
        $this->storeEverything();

        $this->post($this->controller(true), 'saveSecurityDeposit', ['security_deposit_enabled' => 'on']);

        $this->assertSame(30000, $this->payments->loadSettings($this->assetId)->securityDepositAmountCents);
        $this->assertSame('Indiquez le montant de la caution demandée.', FlashMessage::get()['message'] ?? null);
    }

    public function testTheSwitchSavesTrackingAloneAndSaysSo(): void
    {
        $this->storeEverything(false);

        $response = $this->postJson($this->controller(true), ['enabled' => true]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['success' => true, 'enabled' => true, 'message' => 'Suivi des paiements activé.'],
            self::decode($response)
        );
        $stored = $this->payments->loadSettings($this->assetId);
        $this->assertTrue($stored->enabled);
        $this->assertSame(30, $stored->depositPercentage);
        $this->assertSame(30000, $stored->securityDepositAmountCents);
        $this->assertSame(3, $stored->financeAccountId);
    }

    /** Without an account or a category the switch is refused, and answers what is stored. */
    public function testTheSwitchIsRefusedWithoutAnAccountAndAnswersTheStoredState(): void
    {
        $response = $this->postJson($this->controller(true), ['enabled' => true]);

        $this->assertSame(422, $response->getStatusCode());
        $data = self::decode($response);
        $this->assertFalse($data['success']);
        $this->assertFalse($data['enabled']);
        $this->assertStringContainsString('Biens à louer › Compte des paiements', (string) $data['error']);
        $this->assertFalse($this->payments->loadSettings($this->assetId)->enabled);
    }

    public function testTheSwitchRefusesARequestWithoutAValidToken(): void
    {
        $this->storeEverything(false);
        $_POST = [];

        $response = $this->controller(true)->saveTracking(
            new \Tests\RequestWithInput('POST', '/mes-locations/local/reglages/suivi-paiements', [], [], [], [], '{"enabled":true,"_csrf_token":"forged"}'),
            ['slug' => 'local']
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($this->payments->loadSettings($this->assetId)->enabled);
    }

    /** Somebody who does not manage the asset meets the 404 every other write gives. */
    public function testTheSwitchIsNotFoundForSomebodyWhoDoesNotManageTheAsset(): void
    {
        $this->storeEverything(false);
        AuthSession::login(9, 'nobody@test.be', 'identified');

        $response = $this->postJson($this->controller(true), ['enabled' => true]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->payments->loadSettings($this->assetId)->enabled);
    }

    /** Without Finance the deposit and the security deposit still save (D12); tracking does not. */
    public function testWithoutFinanceTheDepositAndTheSecurityDepositStillSave(): void
    {
        $controller = $this->controller(false);

        $this->post($controller, 'saveDeposit', ['deposit_mode' => 'percentage', 'deposit_percentage' => '25']);
        $this->post($controller, 'saveSecurityDeposit', [
            'security_deposit_enabled' => 'on',
            'security_deposit_amount' => '200',
        ]);

        $stored = $this->payments->loadSettings($this->assetId);
        $this->assertSame(25, $stored->depositPercentage);
        $this->assertSame(20000, $stored->securityDepositAmountCents);

        $response = $this->postJson($controller, ['enabled' => true]);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($this->payments->loadSettings($this->assetId)->enabled);
    }
}
