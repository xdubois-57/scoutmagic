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
use Core\View\TwigFactory;
use Modules\Registration\Controller\RegistrationCopySettingsController;
use Modules\Registration\Service\FamilyEmailCopyService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\RequestWithInput;
use Tests\TestTwig;
use Twig\Environment;

/**
 * POST /config/inscriptions/copie — the box « Copie des e-mails
 * d'inscription » of « Réglages » (chantier #843, D9 / IT-06), through the
 * module manifest's own router and the real front controller: the role
 * boundary as declared (admin allowed, chief refused), the CSRF guard,
 * and every refusal of the save — an invalid address, a switched-on copy
 * with nothing to resolve — saving nothing.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationCopySettingsControllerTest extends TestCase
{
    private const PATH = '/config/inscriptions/copie';

    private \PDO $pdo;
    private SettingService $settingService;
    private Environment $twig;
    private RegistrationCopySettingsController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            FamilyEmailCopyService::SETTING_ENABLED,
            '1',
            'boolean',
            'Mettre une adresse en copie',
            'desc',
            'registration',
            null,
            null,
            false
        );
        $this->settingService->register(
            FamilyEmailCopyService::SETTING_ADDRESS,
            '',
            'email',
            'Adresse e-mail en copie',
            'desc',
            'registration',
            null,
            null,
            false
        );
        $this->settingService->register(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, '', 'email', 'Contact', 'desc');

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates', false);
        $this->controller = new RegistrationCopySettingsController(
            $this->twig,
            new FamilyEmailCopyService($this->settingService, $journal),
            $journal
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

    public function testAdminSavesAValidAddress(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['cc_enabled' => '1', 'cc_address' => ' secretariat@unite.example.com ']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertSame('1', $this->stored(FamilyEmailCopyService::SETTING_ENABLED));
        $this->assertSame('secretariat@unite.example.com', $this->stored(FamilyEmailCopyService::SETTING_ADDRESS));
        $this->assertJournalSaysNoAddress();
    }

    /** One rung below the route's floor: refused, nothing saved. */
    public function testOneRoleBelowIsRefused(): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->post(['cc_enabled' => '0', 'cc_address' => 'secretariat@unite.example.com']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('1', $this->stored(FamilyEmailCopyService::SETTING_ENABLED));
        $this->assertSame('', $this->stored(FamilyEmailCopyService::SETTING_ADDRESS));
    }

    public function testAnInvalidAddressSavesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->settingService->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');

        $response = $this->post(['cc_enabled' => '0', 'cc_address' => 'pas-une-adresse']);

        $this->assertRedirectsToSettings($response);
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(FamilyEmailCopyService::MESSAGE_INVALID, $flash['message'] ?? null);
        $this->assertSame('1', $this->stored(FamilyEmailCopyService::SETTING_ENABLED), 'the switch is untouched too.');
        $this->assertSame('', $this->stored(FamilyEmailCopyService::SETTING_ADDRESS));
    }

    /**
     * Switched on with an empty box and no address for the unit: there is
     * nothing to put in copy, so the activation is refused.
     */
    public function testSwitchingOnWithNothingToResolveIsRefused(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->settingService->setInternal(FamilyEmailCopyService::SETTING_ENABLED, '0', 'registration');

        $response = $this->post(['cc_enabled' => '1', 'cc_address' => '']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame(FamilyEmailCopyService::MESSAGE_UNRESOLVABLE, FlashMessage::get()['message'] ?? null);
        $this->assertSame('0', $this->stored(FamilyEmailCopyService::SETTING_ENABLED));
    }

    /** With an address for the unit, an empty box is a valid choice. */
    public function testAnEmptyBoxFallsBackToTheUnitAddress(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->settingService->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');
        $this->settingService->setInternal(
            FamilyEmailCopyService::SETTING_ADDRESS,
            'ancien@unite.example.com',
            'registration'
        );

        $response = $this->post(['cc_enabled' => '1', 'cc_address' => '']);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertSame('', $this->stored(FamilyEmailCopyService::SETTING_ADDRESS));
    }

    /**
     * Switched off, the field is disabled and not submitted: the switch is
     * saved and the address the box held is kept for the day it is
     * switched back on.
     */
    public function testSwitchingOffKeepsTheStoredAddress(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->settingService->setInternal(
            FamilyEmailCopyService::SETTING_ADDRESS,
            'secretariat@unite.example.com',
            'registration'
        );

        $response = $this->post([]);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('0', $this->stored(FamilyEmailCopyService::SETTING_ENABLED));
        $this->assertSame('secretariat@unite.example.com', $this->stored(FamilyEmailCopyService::SETTING_ADDRESS));
    }

    public function testAMissingTokenSavesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->post(['cc_enabled' => '0', 'cc_address' => ''], withToken: false);

        $this->assertRedirectsToSettings($response);
        $this->assertSame('1', $this->stored(FamilyEmailCopyService::SETTING_ENABLED));
    }

    /**
     * The box as the mock-up draws it: the switch on by default, the
     * address usable, the unit's own address as the placeholder, and one
     * « Enregistrer » posting to the route above.
     */
    public function testTheBoxRendersTheMockupsLabelsSwitchedOn(): void
    {
        $html = $this->renderBox(['enabled' => true, 'address' => '', 'unit_address' => 'info@unite.example.com']);

        $this->assertStringContainsString('action="/config/inscriptions/copie"', $html);
        $this->assertStringContainsString("Copie des e-mails d'inscription", $html);
        $this->assertStringContainsString('Mettre une adresse en copie', $html);
        $this->assertStringContainsString('Adresse e-mail en copie', $html);
        $this->assertStringContainsString(
            "S'applique à l'accusé de réception, à l'acceptation et au refus, qu'ils partent à la main ou "
            . 'automatiquement, renvois compris.',
            (string) preg_replace('/\s+/', ' ', $html)
        );
        $this->assertStringContainsString("Laissée vide, c&#039;est l&#039;adresse par défaut de l&#039;unité", $html);
        $this->assertStringContainsString('placeholder="info@unite.example.com (adresse de l&#039;unité)"', $html);
        $this->assertMatchesRegularExpression('/id="registration-cc-enabled"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="registration-cc-address"[^>]*disabled/', $html);
        $this->assertSame(1, preg_match_all('/>\s*Enregistrer\s*</', $html));
        $this->assertStringNotContainsString('alert-warning', $html);
    }

    /** Switched off: the address is greyed out — disabled, and kept. */
    public function testTheBoxSwitchedOffDisablesTheAddress(): void
    {
        $html = $this->renderBox(['enabled' => false, 'address' => 'secretariat@unite.example.com']);

        $this->assertDoesNotMatchRegularExpression('/id="registration-cc-enabled"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="registration-cc-address"[^>]*disabled/', $html);
        $this->assertStringContainsString('value="secretariat@unite.example.com"', $html);
    }

    /**
     * Switched on with nothing to resolve — the unit's address cleared on
     * another page since: the box says the e-mails leave without a copy.
     */
    public function testTheBoxWarnsWhenACopyCannotBeSent(): void
    {
        $html = $this->renderBox(['enabled' => true, 'address' => '', 'unit_address' => '', 'resolvable' => false]);

        $this->assertStringContainsString('les e-mails aux familles partent sans copie', $html);
        // Announced politely by a native live region, not a `role` on a div.
        $this->assertMatchesRegularExpression('/<output[^>]*aria-live="polite"[^>]*>\s*Aucune adresse valide/', $html);
        $this->assertStringNotContainsString('placeholder=', $html);
    }

    /**
     * @param array{enabled: bool, address: string, unit_address?: string, resolvable?: bool} $state
     */
    private function renderBox(array $state): string
    {
        $state += ['unit_address' => '', 'resolvable' => true];

        return TestTwig::create(['registration'], ['csrf_field' => static fn (): string => ''])
            ->render('@registration/config/_settings_copy.html.twig', ['email_copy' => $state]);
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
        $this->assertSame(RegistrationCopySettingsController::class, $resolved->controllerClass);
        $this->assertSame('save', $resolved->action);

        $configFile = sys_get_temp_dir() . '/test_registration_copy_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        try {
            $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
            $frontController->registerController(RegistrationCopySettingsController::class, $this->controller);

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
        $module = $key === FamilyEmailCopyService::SETTING_UNIT_ADDRESS ? null : 'registration';

        return (string) $this->settingService->get($key, $module, 'unset');
    }

    private function assertRedirectsToSettings(Response $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/inscriptions/reglages', $response->getHeaders()['Location'] ?? null);
    }

    private function assertJournalSaysNoAddress(): void
    {
        $statement = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $statement->execute(['registration_email_copy_saved']);
        $context = (string) $statement->fetchColumn();

        $this->assertStringContainsString('custom_address', $context);
        $this->assertStringNotContainsString('secretariat', $context);
    }
}
