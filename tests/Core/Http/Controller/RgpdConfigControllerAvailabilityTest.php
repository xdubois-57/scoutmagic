<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\RgpdConfigController;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The page used to offer "Générer avec l'IA" whenever llm_connector was merely
 * installed. An enabled module with no provider — or with no model on the
 * CAPABLE tier this document is generated on — then failed only after the
 * click. Both surfaces now ask RgpdContentService whether generation can
 * actually run.
 */
class RgpdConfigControllerAvailabilityTest extends TestCase
{
    private function controller(
        RgpdContentService $rgpdContentService,
        bool $moduleEnabled = true
    ): RgpdConfigController {
        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('getEnabledModuleIds')
            ->willReturn($moduleEnabled ? ['llm_connector'] : []);

        $settingService = $this->createStub(SettingService::class);
        $settingService->method('get')->willReturn('default');

        $editableContentService = $this->createStub(EditableContentService::class);
        $editableContentService->method('get')->willReturn('');

        // The template only has to echo the flag under test.
        $twig = new Environment(new ArrayLoader([
            'config/rgpd.html.twig' => '{{ llm_available ? "IA_DISPONIBLE" : "IA_INDISPONIBLE" }}',
        ]));

        return new RgpdConfigController(
            $twig,
            $editableContentService,
            $rgpdContentService,
            $settingService,
            $moduleManager,
            $this->createStub(JournalService::class)
        );
    }

    private function rgpdService(bool $available): RgpdContentService
    {
        $service = $this->createStub(RgpdContentService::class);
        $service->method('isAvailable')->willReturn($available);
        $service->method('getDefaultContent')->willReturn('<h2>Politique</h2>');

        return $service;
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        unset($_SESSION['_csrf_token']);
    }

    public function testThePageOffersGenerationWhenATierIsActuallyUsable(): void
    {
        $response = $this->controller($this->rgpdService(true))
            ->index(new Request('GET', '/config/rgpd', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('IA_DISPONIBLE', $response->getBody());
    }

    public function testThePageHidesGenerationWhenTheModuleIsInstalledButUnusable(): void
    {
        $response = $this->controller($this->rgpdService(false))
            ->index(new Request('GET', '/config/rgpd', [], [], [], []), []);

        $this->assertStringContainsString('IA_INDISPONIBLE', $response->getBody());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonRequest(array $data): Request
    {
        return $this->createConfiguredStub(Request::class, [
            'getRawBody' => (string) json_encode($data),
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
    }

    private function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $token;
    }

    public function testGenerateRefusesCleanlyWhenNoTierIsUsable(): void
    {
        AuthSession::login(1, 'super@example.org', 'superadmin');

        $response = $this->controller($this->rgpdService(false))->generate(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken(), 'prompt' => '']),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('fournisseur IA', $decoded['error']);
    }

    public function testGenerateStillReportsAnEntirelyDisabledModuleSeparately(): void
    {
        AuthSession::login(1, 'super@example.org', 'superadmin');

        $response = $this->controller($this->rgpdService(false), moduleEnabled: false)->generate(
            $this->jsonRequest(['_csrf_token' => $this->csrfToken(), 'prompt' => '']),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('non activé', json_decode($response->getBody(), true)['error']);
    }

    /**
     * The availability gate must sit behind authentication and CSRF, not in
     * front of them — otherwise it answers questions about the site's AI
     * configuration to anyone who can reach the route.
     */
    public function testGenerateRejectsABadCsrfTokenBeforeCheckingAvailability(): void
    {
        AuthSession::login(1, 'super@example.org', 'superadmin');
        $this->csrfToken();

        $response = $this->controller($this->rgpdService(false))->generate(
            $this->jsonRequest(['_csrf_token' => 'wrong', 'prompt' => '']),
            []
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
