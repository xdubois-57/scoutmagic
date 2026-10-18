<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\FirstStepsService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Controller\FirstStepsController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * « Masquer » on the « Premiers pas » band (issue #718 § A).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FirstStepsControllerTest extends TestCase
{
    private FirstStepsService $service;
    private FirstStepsController $controller;

    protected function setUp(): void
    {
        $settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        FirstStepsService::register($settings);
        $this->service = new FirstStepsService($settings);
        $this->service->markPending();

        $twig = new Environment(new ArrayLoader([]), ['cache' => false, 'autoescape' => 'html']);
        $this->controller = new FirstStepsController($twig, $this->service);
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testDismissWithoutAValidTokenChangesNothing(): void
    {
        $response = $this->controller->dismiss(new Request('POST', '/config/premiers-pas/masquer', [], [], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, FlashMessage::get()['message'] ?? null);
        $this->assertTrue($this->service->shouldShowTo('superadmin'));
    }

    public function testDismissHidesTheBandAndReturnsToTheSamePage(): void
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request(
            'POST',
            '/config/premiers-pas/masquer',
            [],
            ['_csrf_token' => $token],
            [],
            ['HTTP_REFERER' => 'https://unite.example/config/modules']
        );

        $response = $this->controller->dismiss($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/modules', $response->getHeaders()['Location'] ?? null);
        $this->assertFalse($this->service->shouldShowTo('superadmin'));
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    /**
     * The Referer is the visitor's to forge: never followed off-site.
     */
    public function testAForeignRefererIsNotFollowed(): void
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $request = new Request(
            'POST',
            '/config/premiers-pas/masquer',
            [],
            ['_csrf_token' => $token],
            [],
            ['HTTP_REFERER' => 'https://evil.example/phish']
        );

        $location = (string) ($this->controller->dismiss($request, [])->getHeaders()['Location'] ?? '');

        $this->assertStringStartsWith('/', $location);
        $this->assertStringNotContainsString('evil.example', $location);
    }
}
