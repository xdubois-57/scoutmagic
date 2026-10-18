<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\RegistrationAutomationSettingsController;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Service\AutomaticAcceptancePolicy;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\RequestWithInput;
use Twig\Environment;

/**
 * POST /config/inscriptions/decisions-automatiques — the « Décisions
 * automatiques » sub-box of « Capacités par branche » (chantier #843, D7 /
 * IT-09), through the module manifest's own router and the real front
 * controller: the role boundary as declared (admin allowed, chief
 * refused), the CSRF guard, and every refusal of the save — an invalid
 * delay, an automatic acceptance without its prerequisites — saving
 * nothing.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationAutomationSettingsControllerTest extends TestCase
{
    private const PATH = '/config/inscriptions/decisions-automatiques';

    private \PDO $pdo;
    private SettingService $settingService;
    private Environment $twig;
    private RegistrationAutomationSettingsController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settingService);

        $journal = new JournalService(new JournalRepository($this->pdo));
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $emails = new DecisionEmailRepository($this->pdo);
        $decisionEmails = new DecisionEmailService($emails, $journal);
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false);
        $automaticEmails = new AutomaticDecisionEmailService(
            $this->settingService,
            $decisionEmails
        );
        $slotService = RegistrationTestHelper::slotService($this->pdo, $encryption, $this->settingService);
        $this->controller = new RegistrationAutomationSettingsController(
            $this->twig,
            $automaticEmails,
            $journal,
            new AutomaticAcceptancePolicy($this->settingService, $slotService, $automaticEmails)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testAdminSwitchesItOnWithADelayOnceBothTemplatesAreWritten(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '48']);

        $this->assertRedirectsToSettings($response);
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringStartsWith(
            "Réglages enregistrés. Les e-mails d'acceptation et de refus partiront 48 h après chaque décision.",
            $flash['message'] ?? ''
        );
        $this->assertSame('1', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
        $this->assertSame('48', $this->stored(AutomaticDecisionEmailService::SETTING_DELAY_HOURS));

        $statement = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ?');
        $statement->execute(['registration_decision_email_automation_saved']);
        $this->assertSame(
            ['enabled' => true, 'delay_hours' => 48],
            json_decode((string) $statement->fetchColumn(), true)
        );
    }

    /** One rung below the route's floor: refused, nothing saved. */
    public function testOneRoleBelowIsRefused(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '12']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('0', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
        $this->assertSame('24', $this->stored(AutomaticDecisionEmailService::SETTING_DELAY_HOURS));
    }

    /**
     * Issue #932: the decision templates ship with a text, so the sending
     * switches on on a fresh install — nothing written beforehand.
     */
    public function testSwitchingOnNeedsNoTemplateToHaveBeenWritten(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '12']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertSame('1', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
        $this->assertSame('12', $this->stored(AutomaticDecisionEmailService::SETTING_DELAY_HOURS));
    }

    public function testAnInvalidDelayIsRefusedAndSavesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '0']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame(
            ['type' => 'error', 'message' => AutomaticDecisionEmailService::MESSAGE_DELAY_INVALID],
            FlashMessage::get()
        );
        $this->assertSame('0', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
    }

    /** An unchecked switch is not posted: that is switching off. */
    public function testAnUncheckedSwitchSwitchesOff(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $response = $this->post(['delay_hours' => '24']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertSame('0', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
    }

    public function testAMissingTokenSavesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '12'], withToken: false);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('0', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
    }

    /**
     * IT-10: the automatic acceptance switched on in the same save as the
     * automatic sending, with its date — the four settings saved together,
     * the confirmation saying from when, and a journal entry of its own
     * carrying the switch and the date only.
     */
    public function testAdminSwitchesTheAutomaticAcceptanceOnWithTheSendingAndADate(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post([
            'auto_send' => '1', 'delay_hours' => '24', 'auto_accept' => '1', 'auto_accept_from' => '2026-10-15',
        ]);

        $this->assertRedirectsToSettings($response);
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringContainsString(
            'À partir du 15/10/2026, les demandes recommandées « Oui » déposées depuis cette date seront acceptées '
            . 'par le site',
            $flash['message'] ?? ''
        );
        $this->assertStringEndsWith(
            "Leurs familles recevront l'e-mail d'acceptation 24 h plus tard.",
            $flash['message'] ?? ''
        );
        $this->assertSame('1', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
        $this->assertSame('1', $this->stored(AutomaticAcceptancePolicy::SETTING_ENABLED));
        $this->assertSame('2026-10-15', $this->stored(AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM));

        $statement = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ?');
        $statement->execute(['registration_auto_accept_saved']);
        $this->assertSame(
            ['enabled' => true, 'effective_from' => '2026-10-15'],
            json_decode((string) $statement->fetchColumn(), true)
        );
    }

    /** One rung below the route's floor: the automatic acceptance stays off. */
    public function testOneRoleBelowCannotSwitchTheAutomaticAcceptanceOn(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $response = $this->post([
            'auto_send' => '1', 'delay_hours' => '24', 'auto_accept' => '1', 'auto_accept_from' => '2026-10-15',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('0', $this->stored(AutomaticAcceptancePolicy::SETTING_ENABLED));
        $this->assertSame('', $this->stored(AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM));
    }

    /**
     * Each prerequisite refuses the switch on, and nothing of the sub-box is
     * saved — not even the automatic sending posted with it.
     *
     * @param array<string, string> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAcceptances')]
    public function testTheAutomaticAcceptanceIsRefusedWithoutItsPrerequisites(
        array $body,
        bool $waitlist,
        string $message
    ): void {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->settingService->set('registration_waitlist_enabled', $waitlist ? '1' : '0', 'registration');

        $response = $this->post($body + ['delay_hours' => '36']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame(['type' => 'error', 'message' => $message], FlashMessage::get());
        $this->assertSame('0', $this->stored(AutomaticAcceptancePolicy::SETTING_ENABLED));
        $this->assertSame('', $this->stored(AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM));
        $this->assertSame('24', $this->stored(AutomaticDecisionEmailService::SETTING_DELAY_HOURS));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: bool, 2: string}>
     */
    public static function refusedAcceptances(): array
    {
        $on = ['auto_send' => '1', 'auto_accept' => '1', 'auto_accept_from' => '2026-10-15'];

        return [
            'waitlists not managed' => [$on, false, AutomaticAcceptancePolicy::MESSAGE_WAITLIST_REQUIRED],
            'sending not on' => [
                ['auto_accept' => '1', 'auto_accept_from' => '2026-10-15'],
                true,
                AutomaticAcceptancePolicy::MESSAGE_AUTO_SEND_REQUIRED,
            ],
            'no date' => [
                ['auto_send' => '1', 'auto_accept' => '1', 'auto_accept_from' => ''],
                true,
                AutomaticAcceptancePolicy::MESSAGE_DATE_REQUIRED,
            ],
            'not a date, even switched off' => [
                ['auto_send' => '1', 'auto_accept_from' => '2026-02-30'],
                true,
                AutomaticAcceptancePolicy::MESSAGE_DATE_INVALID,
            ],
        ];
    }

    /** Unchecked, it switches off, keeps its date, and the confirmation says so. */
    public function testAnUncheckedAcceptanceSwitchSwitchesItOff(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);
        RegistrationTestHelper::enableAutomaticAcceptance($this->settingService, '2026-10-15');

        $response = $this->post(['auto_send' => '1', 'delay_hours' => '24', 'auto_accept_from' => '2026-10-15']);

        $this->assertRedirectsToSettings($response);
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringEndsWith(
            "L'acceptation automatique est désactivée : chaque demande se décide à la main.",
            $flash['message'] ?? ''
        );
        $this->assertSame('0', $this->stored(AutomaticAcceptancePolicy::SETTING_ENABLED));
        $this->assertSame('2026-10-15', $this->stored(AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM));
        $this->assertSame('1', $this->stored(AutomaticDecisionEmailService::SETTING_ENABLED));
    }

    /** A save that leaves the acceptance as it was journals nothing about it. */
    public function testASaveThatLeavesTheAcceptanceAsItWasJournalsNothingAboutIt(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->post(['delay_hours' => '36']);

        $this->assertSame('36', $this->stored(AutomaticDecisionEmailService::SETTING_DELAY_HOURS));
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $count->execute(['registration_auto_accept_saved']);
        $this->assertSame(0, (int) $count->fetchColumn());
    }


    /**
     * @param array<string, string> $body
     */
    private function post(array $body, bool $withToken = true): Response
    {
        if ($withToken) {
            $body['_csrf_token'] = CsrfGuard::generateToken();
        }
        $request = new RequestWithInput('POST', self::PATH, [], $body, [], []);

        $router = $this->manifestRouter();
        $resolved = $router->resolve($request);
        $this->assertNotNull($resolved, 'the manifest declares POST ' . self::PATH);
        $this->assertSame(RegistrationAutomationSettingsController::class, $resolved->controllerClass);
        $this->assertSame('save', $resolved->action);

        $configFile = sys_get_temp_dir() . '/test_registration_automation_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        try {
            $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
            $frontController->registerController(RegistrationAutomationSettingsController::class, $this->controller);

            return $frontController->handle($request);
        } finally {
            @unlink($configFile);
        }
    }

    /** Every route of the module, as its manifest declares it. */
    private function manifestRouter(): Router
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $router = new Router();
        foreach ($manifest['routes'] as $route) {
            $router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min'],
                $route['breadcrumb'] ?? null,
                'registration'
            );
        }

        return $router;
    }

    private function stored(string $key): string
    {
        return (string) $this->settingService->get($key, 'registration', 'unset');
    }

    private function assertRedirectsToSettings(Response $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/inscriptions/reglages', $response->getHeaders()['Location'] ?? null);
    }
}
