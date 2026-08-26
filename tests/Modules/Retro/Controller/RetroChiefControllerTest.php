<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Request;
use Core\Module\ModuleManager;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Modules\Retro\Controller\RetroChiefController;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\RetroException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RetroChiefControllerTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private SettingService $settingService;
    private BoardService $boardService;
    private RetroChiefController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->boardRepository = new BoardRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->boardService = $this->createMock(BoardService::class);
        $this->boardService->method('publicUrl')->willReturn('/r/token');

        $scoutYearResolver = $this->createMock(ScoutYearResolver::class);
        $scoutYearResolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear(1, '2025-2026', null));
        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')->willReturn([]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/retro/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'retro');
        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new TwigFunction('param', fn() => ''));

        $this->controller = new RetroChiefController(
            $twig, $this->boardRepository, $this->boardService, $this->settingService, $scoutYearResolver, $moduleManager
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(3, 'chief@test.be', 'chief');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function csrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        return $token;
    }

    public function testIndexRendersSuccessfully(): void
    {
        $response = $this->controller->index(new Request('GET', '/retro', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateAllowedForIntendantByDefault(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateForbiddenBelowIntendant(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'identified@test.be', 'identified');

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateForbiddenWhenSettingRaisesTheThresholdToAdmin(): void
    {
        $this->settingService->register('retro_role_min_create_board', 'admin', 'select', 'l', 'd', 'retro');
        $this->settingService->set('retro_role_min_create_board', 'admin', 'retro');
        // Logged in as 'chief' (setUp) — below the raised 'admin' threshold.

        $response = $this->controller->create(new Request('GET', '/retro/create', [], [], [], []), []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStoreRequiresCsrf(): void
    {
        $request = new Request('POST', '/retro', [], ['_csrf_token' => 'bad', 'title' => 'Camp'], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreCreatesABoardAndRedirects(): void
    {
        $this->boardService->expects($this->once())->method('create')->willReturn(
            $this->boardRepository->findById($this->boardRepository->create(
                'Camp', '2026-07-01', null, 'tok', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3
            ))
        );
        $token = $this->csrfToken();
        $request = new Request('POST', '/retro', [], [
            '_csrf_token' => $token, 'title' => 'Camp', 'vote_mode' => 'unlimited', 'anti_duplicate_mode' => 'cookie',
            'max_comment_length' => '140', 'auto_close_delay' => '7d',
        ], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testStoreRedirectsBackToFormOnValidationError(): void
    {
        $this->boardService->method('create')->willThrowException(new RetroException('Le titre est obligatoire.'));
        $token = $this->csrfToken();
        $request = new Request('POST', '/retro', [], ['_csrf_token' => $token, 'title' => ''], [], []);

        $response = $this->controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/retro/create', (string) ($response->getHeaders()['Location'] ?? ''));
    }

    public function testEditReturns404ForUnknownBoard(): void
    {
        $response = $this->controller->edit(new Request('GET', '/retro/999/edit', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testEditRendersForAnExistingBoard(): void
    {
        $id = $this->boardRepository->create('Camp', '2026-07-01', null, 'tok', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);

        $response = $this->controller->edit(new Request('GET', '/retro/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCloseRequiresCsrf(): void
    {
        $request = new Request('POST', '/retro/1/close', [], ['_csrf_token' => 'bad'], [], []);

        $response = $this->controller->close($request, ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
        $this->boardService->expects($this->never())->method('close');
    }

    public function testCloseForbiddenBelowChiefEvenIfCreateThresholdIsLower(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        $token = $this->csrfToken();

        $response = $this->controller->close(new Request('POST', '/retro/1/close', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCloseCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('close');
        $token = $this->csrfToken();

        $response = $this->controller->close(new Request('POST', '/retro/1/close', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testRegenerateLinkForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        $token = $this->csrfToken();

        $response = $this->controller->regenerateLink(new Request('POST', '/retro/1/regenerate-link', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReopenRequiresCsrf(): void
    {
        $this->boardService->expects($this->never())->method('reopen');

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => 'bad'], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testReopenForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        $token = $this->csrfToken();

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReopenCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('reopen');
        $token = $this->csrfToken();

        $response = $this->controller->reopen(new Request('POST', '/retro/1/reopen', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testArchiveForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        $token = $this->csrfToken();

        $response = $this->controller->archive(new Request('POST', '/retro/1/archive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testArchiveCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('archive');
        $token = $this->csrfToken();

        $response = $this->controller->archive(new Request('POST', '/retro/1/archive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUnarchiveForbiddenBelowChief(): void
    {
        AuthSession::logout();
        AuthSession::login(3, 'intendant@test.be', 'intendant');
        $token = $this->csrfToken();

        $response = $this->controller->unarchive(new Request('POST', '/retro/1/unarchive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnarchiveCallsBoardServiceWhenAuthorized(): void
    {
        $this->boardService->expects($this->once())->method('unarchive');
        $token = $this->csrfToken();

        $response = $this->controller->unarchive(new Request('POST', '/retro/1/unarchive', [], ['_csrf_token' => $token], [], []), ['id' => '1']);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testIndexSeparatesArchivedBoardsFromActiveOnes(): void
    {
        $this->boardRepository->create('Active', '2026-07-01', null, 'tok-active', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);
        $archivedId = $this->boardRepository->create('Archived', '2026-06-01', null, 'tok-archived', null, true, 'unlimited', 5, true, 'cookie', 140, '7d', null, 3);
        $this->boardRepository->close($archivedId);
        $this->boardRepository->archive($archivedId);

        $response = $this->controller->index(new Request('GET', '/retro', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Active', $response->getBody());
        $this->assertStringContainsString('Rétrospectives archivées (1)', $response->getBody());
    }
}
