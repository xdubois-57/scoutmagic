<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Modules\Retro\Controller\RetroConfigController;
use Modules\Retro\Service\ModerationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
class RetroConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settingService;
    private MemberService $memberService;
    private ScoutYearService $scoutYearService;
    private ?ModerationService $moderationService = null;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        foreach ([
            'retro_role_min_create_board' => ['intendant', 'select'],
            'retro_role_min_close_board' => ['chief', 'select'],
            'retro_default_max_comment_length' => ['140', 'number'],
            'retro_default_vote_budget' => ['5', 'number'],
            'retro_polling_interval_seconds' => ['8', 'number'],
            'retro_moderation_mode' => ['disabled', 'select'],
        ] as $key => [$default, $type]) {
            $this->settingService->register($key, $default, $type, $key, $key, 'retro');
        }

        // Authorized by default (chef d'unité) — dedicated tests below
        // override this to prove the denial path. The underlying STAFFDU
        // logic itself is fully tested against real fixtures by
        // Core\Member\MemberServiceTest.
        $this->memberService = $this->createMock(MemberService::class);
        $this->memberService->method('isUnitChief')->willReturn(true);
        $this->scoutYearService = $this->createMock(ScoutYearService::class);
        $this->scoutYearService->method('getCurrentYear')->willReturn(['id' => 1, 'label' => '2025-2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31']);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/retro/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'retro');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $this->twig->addFunction(new TwigFunction('param', fn() => ''));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(3, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function controller(?ModerationService $moderationService = null): RetroConfigController
    {
        return new RetroConfigController(
            $this->twig, $this->settingService, new JournalService(new JournalRepository($this->pdo)),
            $this->memberService, $this->scoutYearService, $moderationService
        );
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    private function postRequest(array $data): Request
    {
        return new Request('POST', '/config/retro', [], $data, [], []);
    }

    public function testIndexRendersDefaults(): void
    {
        $response = $this->controller()->index(new Request('GET', '/config/retro', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Rétrospectives', $response->getBody());
    }

    public function testIndexDeniesANonUnitChief(): void
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('isUnitChief')->willReturn(false);
        $controller = new RetroConfigController(
            $this->twig, $this->settingService, new JournalService(new JournalRepository($this->pdo)),
            $memberService, $this->scoutYearService
        );

        $response = $controller->index(new Request('GET', '/config/retro', [], [], [], []), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveDeniesANonUnitChief(): void
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('isUnitChief')->willReturn(false);
        $controller = new RetroConfigController(
            $this->twig, $this->settingService, new JournalService(new JournalRepository($this->pdo)),
            $memberService, $this->scoutYearService
        );

        $response = $controller->save($this->postRequest(['_csrf_token' => $this->csrfToken()]), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveValidatesCsrf(): void
    {
        $response = $this->controller()->save($this->postRequest(['_csrf_token' => 'bad']), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSavePersistsValidValues(): void
    {
        $token = $this->csrfToken();

        $response = $this->controller()->save($this->postRequest([
            '_csrf_token' => $token,
            'retro_role_min_create_board' => 'chief',
            'retro_role_min_close_board' => 'admin',
            'retro_default_max_comment_length' => '160',
            'retro_default_vote_budget' => '7',
            'retro_polling_interval_seconds' => '15',
            'retro_moderation_mode' => 'disabled',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('chief', $this->settingService->get('retro_role_min_create_board', 'retro'));
        $this->assertSame('admin', $this->settingService->get('retro_role_min_close_board', 'retro'));
        $this->assertSame('160', $this->settingService->get('retro_default_max_comment_length', 'retro'));
        $this->assertSame('7', $this->settingService->get('retro_default_vote_budget', 'retro'));
        $this->assertSame('15', $this->settingService->get('retro_polling_interval_seconds', 'retro'));
    }

    public function testSaveRejectsInvalidRoleMinCreate(): void
    {
        $response = $this->controller()->save($this->postRequest([
            '_csrf_token' => $this->csrfToken(),
            'retro_role_min_create_board' => 'superadmin',
        ]), []);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testSaveRejectsMaxCommentLengthOutOfRange(): void
    {
        $response = $this->controller()->save($this->postRequest([
            '_csrf_token' => $this->csrfToken(),
            'retro_default_max_comment_length' => '500',
        ]), []);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testSaveRejectsPollingIntervalOutOfRange(): void
    {
        $response = $this->controller()->save($this->postRequest([
            '_csrf_token' => $this->csrfToken(),
            'retro_polling_interval_seconds' => '0',
        ]), []);

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * The module spec's core safety rule: "disabled (only option if AI is
     * not enabled)" — a non-disabled mode must never persist without an
     * available AI connector, regardless of what the client submits.
     */
    public function testSaveRejectsNonDisabledModerationModeWithoutAi(): void
    {
        $response = $this->controller(null)->save($this->postRequest([
            '_csrf_token' => $this->csrfToken(),
            'retro_moderation_mode' => 'enforced',
        ]), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('disabled', $this->settingService->get('retro_moderation_mode', 'retro', 'disabled'));
    }

    public function testSavePersistsModerationModeWhenAiIsAvailable(): void
    {
        $moderationService = $this->createMock(ModerationService::class);
        $moderationService->method('isAvailable')->willReturn(true);

        $response = $this->controller($moderationService)->save($this->postRequest([
            '_csrf_token' => $this->csrfToken(),
            'retro_moderation_mode' => 'enforced',
        ]), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('enforced', $this->settingService->get('retro_moderation_mode', 'retro'));
    }

    public function testIndexShowsAiUnavailableWarningWithoutModerationService(): void
    {
        $response = $this->controller(null)->index(new Request('GET', '/config/retro', [], [], [], []), []);

        $this->assertStringContainsString('Aucun connecteur IA actif', $response->getBody());
    }
}
