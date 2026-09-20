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
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Controller\RentalPricingController;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetReminderRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * What POSTing the « Rappels » section actually stores (§6.29).
 *
 * The one distinction this whole screen rests on is asserted from both
 * sides here: **an empty delay means « prends le défaut de l'unité », never
 * « jamais »**, and switching a reminder off is the checkbox's job. Fold
 * the two together and 0 — the day itself — ends up one typo away from
 * "never", on a screen whose mistakes are invisible because nobody notices
 * a message they never expected.
 *
 * The authorisation side of the same action is Controller\RentalRbacTest's,
 * which drives every settings write through the same door.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalReminderSettingsTest extends TestCase
{
    private \PDO $pdo;
    private \Twig\Environment $twig;
    private RentalPricingController $controller;
    private RentalAssetReminderRepository $reminderRepository;
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
            $encryption,
            Connection::withPdo($this->pdo)
        );

        $this->reminderRepository = new RentalAssetReminderRepository($this->pdo);

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
            null,
            $this->reminderRepository
        );

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
     * Every field the form posts, with one reminder's values replaced —
     * exactly what the browser sends, because a partial POST would let a
     * test pass on a form that never renders.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function formBody(array $overrides = []): array
    {
        $body = [];
        foreach (ReminderKind::cases() as $kind) {
            $body['days_' . $kind->value] = '';
            $body['active_' . $kind->value] = '1';
        }

        foreach ($overrides as $field => $value) {
            if ($value === '') {
                // An unchecked box posts NOTHING; an empty delay posts an
                // empty string. Telling the two apart is the point.
                $body[$field] = '';
                continue;
            }

            $body[$field] = $value;
        }

        return $body;
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
            '/mes-locations/{slug}/reglages/rappels',
            RentalPricingController::class,
            'saveReminders',
            'identified'
        );

        $configFile = sys_get_temp_dir() . '/test_rental_reminders_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(RentalPricingController::class, $this->controller);
        $response = $frontController->handle(
            new Request('POST', '/mes-locations/' . $slug . '/reglages/rappels', [], $body, [], [])
        );

        $_POST = [];
        @unlink($configFile);

        return $response;
    }

    public function testAFormWhereNothingWasChangedStoresNothingAtAll(): void
    {
        $this->save($this->formBody());

        $this->assertSame([], $this->reminderRepository->findForAsset($this->assetId));
    }

    public function testItLandsBackOnTheSectionItCameFrom(): void
    {
        $response = $this->save($this->formBody());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            '/mes-locations/local-saint-georges/reglages#rappels',
            $response->getHeaders()['Location']
        );
    }

    public function testADelayTypedInIsStored(): void
    {
        $this->save($this->formBody(['days_unanswered_request' => '10']));

        $this->assertSame(
            ['days' => 10, 'active' => true],
            $this->reminderRepository->findForAsset($this->assetId)['unanswered_request']
        );
    }

    /**
     * « Le jour même », and the value most easily lost by code that reads
     * an empty field and a zero as the same thing.
     */
    public function testZeroIsADelayAndNotAnEmptyField(): void
    {
        $this->save($this->formBody(['days_contract_missing' => '0']));

        $this->assertSame(
            ['days' => 0, 'active' => true],
            $this->reminderRepository->findForAsset($this->assetId)['contract_missing']
        );
    }

    /**
     * An unchecked checkbox posts nothing at all — absence IS the signal,
     * and a missing field must not be read as "leave it as it was".
     */
    public function testAnUncheckedBoxSwitchesTheReminderOff(): void
    {
        $body = $this->formBody();
        unset($body['active_arrival_inventory']);

        $this->save($body);

        $this->assertSame(
            ['days' => null, 'active' => false],
            $this->reminderRepository->findForAsset($this->assetId)['arrival_inventory']
        );
    }

    public function testCheckingABoxAgainPutsTheReminderBackOnTheUnitsDefault(): void
    {
        $body = $this->formBody();
        unset($body['active_arrival_inventory']);
        $this->save($body);

        $this->save($this->formBody());

        $this->assertSame([], $this->reminderRepository->findForAsset($this->assetId));
    }

    public function testClearingADelayGoesBackToInheritingRatherThanToNever(): void
    {
        $this->save($this->formBody(['days_unanswered_request' => '10']));

        $this->save($this->formBody());

        $this->assertArrayNotHasKey('unanswered_request', $this->reminderRepository->findForAsset($this->assetId));
    }

    /**
     * Whatever somebody types, the screen has to end up with a number of
     * days or with nothing — never with a stored value the planner then
     * has to defend itself against.
     */
    public function testTextInADelayFieldIsReadAsSayingNothing(): void
    {
        $this->save($this->formBody(['days_unanswered_request' => 'bientôt']));

        $this->assertSame([], $this->reminderRepository->findForAsset($this->assetId));
    }

    public function testANegativeDelayIsBroughtBackToTheDayItself(): void
    {
        $this->save($this->formBody(['days_unanswered_request' => '-5']));

        $this->assertSame(
            0,
            $this->reminderRepository->findForAsset($this->assetId)['unanswered_request']['days']
        );
    }

    /**
     * Two things at once, which is what a manager setting up a remorque
     * actually does: a longer delay on one reminder and two others off.
     */
    public function testASectionSavedWholeKeepsEveryDifferenceAndOnlyTheDifferences(): void
    {
        $body = $this->formBody(['days_unanswered_request' => '10']);
        unset($body['active_arrival_inventory'], $body['active_departure_inventory']);

        $this->save($body);

        $overrides = $this->reminderRepository->findForAsset($this->assetId);
        $keys = array_keys($overrides);
        sort($keys);
        $this->assertSame(
            ['arrival_inventory', 'departure_inventory', 'unanswered_request'],
            $keys
        );
        $this->assertSame(10, $overrides['unanswered_request']['days']);
        $this->assertFalse($overrides['arrival_inventory']['active']);
    }

    public function testAnAssetTheVisitorDoesNotManageIsAnswered404(): void
    {
        AuthSession::logout();
        AuthSession::login(2, 'nobody@test.be', 'identified');

        $this->assertSame(404, $this->save($this->formBody())->getStatusCode());
        $this->assertSame([], $this->reminderRepository->findForAsset($this->assetId));
    }
}
