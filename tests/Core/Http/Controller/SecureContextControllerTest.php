<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SecureContextController;
use Core\Http\InsecureBrowserAccess;
use Core\Http\Request;
use Core\Http\RequestScheme;
use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The beacon fallback (#751): CSRF-checked, and recorded only while HTTPS
 * is required.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SecureContextControllerTest extends TestCase
{
    private SettingService $settings;
    private SecureContextController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        \Core\Security\AuthSession::login(1, 'chef@example.invalid', 'identified');
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        $this->controller = new SecureContextController(
            \Tests\TestTwig::create(),
            new InsecureBrowserAccess($this->settings)
        );
    }

    protected function tearDown(): void
    {
        // tests/bootstrap.php runs the suite with the development exception.
        RequestScheme::setHttpsRequired(false);
        $_SESSION = [];
    }

    private function post(string $token): int
    {
        $request = new Request('POST', InsecureBrowserAccess::BEACON_PATH, [], ['_csrf_token' => $token], [], []);

        return $this->controller->report($request, [])->getStatusCode();
    }

    /** The meta tag, the constant and the route must name one path. */
    public function testTheBeaconPathIsTheOneRoutedAndTheOneThePageAnnounces(): void
    {
        $root = dirname(__DIR__, 4);

        $this->assertSame('/api/connexion-non-securisee', InsecureBrowserAccess::BEACON_PATH);
        $this->assertMatchesRegularExpression(
            "~addRoute\\(\\s*'POST',\\s*'/api/connexion-non-securisee',\\s*\\\\Core\\\\Http\\\\Controller\\\\"
                . "SecureContextController::class,\\s*'report',\\s*'identified'~",
            (string) file_get_contents($root . '/public/index.php')
        );
        $this->assertStringContainsString(
            'content="/api/connexion-non-securisee"',
            (string) file_get_contents($root . '/core/View/templates/base.html.twig')
        );
    }

    public function testAValidBeaconRecordsTheObservationWhileHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);

        $this->assertSame(204, $this->post(CsrfGuard::generateToken()));
        $this->assertNotNull((new InsecureBrowserAccess($this->settings))->lastObservedAt());
    }

    public function testABeaconWithoutAValidTokenRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(true);
        CsrfGuard::generateToken();

        $this->assertSame(403, $this->post('forged'));
        $this->assertNull((new InsecureBrowserAccess($this->settings))->lastObservedAt());
    }

    public function testAnInstallationThatToleratesHttpRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(false);

        $this->assertSame(204, $this->post(CsrfGuard::generateToken()));
        $this->assertNull((new InsecureBrowserAccess($this->settings))->lastObservedAt());
    }
}
