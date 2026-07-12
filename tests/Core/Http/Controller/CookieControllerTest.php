<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Cookie\CookieConsentService;
use Core\Http\Controller\CookieController;
use Core\Http\Request;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class CookieControllerTest extends TestCase
{
    private CookieController $controller;
    private CookieConsentService $cookieConsentService;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', false);
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

        $this->cookieConsentService = new CookieConsentService([]);
        $this->controller = new CookieController($twig, $this->cookieConsentService);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testPreferencesRendersPageWithAllThreeCategories(): void
    {
        $request = new Request('GET', '/cookies', [], [], [], []);
        $response = $this->controller->preferences($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Cookies strictement nécessaires', $body);
        $this->assertStringContainsString('Cookies fonctionnels', $body);
        $this->assertStringContainsString('analyse', $body);
    }

    public function testPreferencesShowsToujousActifBadge(): void
    {
        $request = new Request('GET', '/cookies', [], [], [], []);
        $response = $this->controller->preferences($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('Toujours actif', $body);
    }

    public function testPreferencesShowsCheckedToggleWhenConsented(): void
    {
        $jar = ['cookie_consent' => '{"functional":true,"analytics":false}'];
        $service = new CookieConsentService($jar);
        $controller = $this->createController($service);

        $request = new Request('GET', '/cookies', [], [], [], []);
        $response = $controller->preferences($request, []);

        // Functional should be checked
        $this->assertStringContainsString('checked', $response->getBody());
    }

    public function testSaveValidatesCsrfToken(): void
    {
        $request = new Request('POST', '/cookies/save', [], ['_csrf_token' => 'invalid'], [], []);
        $response = $this->controller->save($request, []);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSaveUpdatesConsentAndRedirects(): void
    {
        $token = CsrfGuard::generateToken();
        $request = new Request('POST', '/cookies/save', [], [
            '_csrf_token' => $token,
            'functional' => 'on',
        ], [], []);

        $response = $this->controller->save($request, []);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAcceptAllReturnJsonSuccess(): void
    {
        $token = CsrfGuard::generateToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $request = new Request('POST', '/cookies/accept-all', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        $response = $this->controller->acceptAll($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
    }

    public function testRejectAllReturnJsonSuccess(): void
    {
        $token = CsrfGuard::generateToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $request = new Request('POST', '/cookies/reject-all', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        $response = $this->controller->rejectAll($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertTrue($data['success']);
    }

    public function testAcceptAllAndRejectAllAcceptCsrfFromHeader(): void
    {
        $token = CsrfGuard::generateToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $request = new Request('POST', '/cookies/accept-all', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        $response = $this->controller->acceptAll($request, []);
        $this->assertSame(200, $response->getStatusCode());

        // Reset and test reject with header
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $request = new Request('POST', '/cookies/reject-all', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        $response = $this->controller->rejectAll($request, []);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAcceptAllReturns403WithoutCsrf(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $request = new Request('POST', '/cookies/accept-all', [], [], [], []);
        $response = $this->controller->acceptAll($request, []);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRejectAllReturns403WithoutCsrf(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $request = new Request('POST', '/cookies/reject-all', [], [], [], []);
        $response = $this->controller->rejectAll($request, []);
        $this->assertSame(403, $response->getStatusCode());
    }

    private function createController(CookieConsentService $service): CookieController
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', $service->hasConsented());
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

        return new CookieController($twig, $service);
    }
}
