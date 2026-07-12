<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\ConfigModeController;
use Core\Http\Request;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class ConfigModeControllerTest extends TestCase
{
    private ConfigModeController $controller;

    protected function setUp(): void
    {
        $twig = new Environment(new ArrayLoader([]), ['cache' => false, 'autoescape' => 'html']);
        $this->controller = new ConfigModeController($twig);
    }

    public function testActivateRejectsMissingCsrf(): void
    {
        $request = new Request('POST', '/config-mode/activate', [], [], [], []);
        $response = $this->controller->activate($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeactivateRejectsMissingCsrf(): void
    {
        $request = new Request('POST', '/config-mode/deactivate', [], [], [], []);
        $response = $this->controller->deactivate($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testActivateWithValidCsrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        $request = new Request('POST', '/config-mode/activate', [], ['_csrf_token' => $token], [], []);
        $response = $this->controller->activate($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }
}
