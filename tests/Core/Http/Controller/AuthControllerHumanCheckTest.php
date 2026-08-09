<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AuthController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthService;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\MagicLinkResult;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Core\Security\HumanCheck integration on POST /login/magic-link — the
 * one call site that only applies the honeypot + minimum-delay barriers
 * (enforceRateLimit: false, see AuthController::requestMagicLink()'s own
 * comment on why: AuthService::requestMagicLink() already rate-limits by
 * email, ARCHITECTURE.md §8's iteration-1 spec).
 *
 * @group database
 */
class AuthControllerHumanCheckTest extends TestCase
{
    private AuthController $controller;
    private AuthService $authService;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $settings = new SettingService(new SettingRepository($pdo));
        $settings->register('human_check_min_delay_seconds', '2', 'number', 'Délai minimum', 'Description de test.');
        $settings->register('human_check_form_validity_seconds', '100', 'number', 'Durée de validité', 'Description de test.');
        $humanCheck = new HumanCheckService(
            $encryption,
            new HumanCheckRateLimitRepository($pdo),
            $settings,
            new JournalService(new JournalRepository($pdo))
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test-csrf-token'));
        $twig->addFunction(new \Twig\TwigFunction('editable', fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn (): string => ''));

        $this->authService = $this->createMock(AuthService::class);
        $this->controller = new AuthController($twig, $this->authService);
        $this->controller->setHumanCheck($humanCheck);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    /**
     * @return array<string, string>
     */
    private function humanCheckFields(): array
    {
        // Render the real login page to get a real challenge — same path
        // a browser would take, rather than reaching into the service
        // directly.
        $request = new Request('GET', '/login', [], [], [], []);
        $response = $this->controller->login($request, []);
        preg_match('/name="human_check_token" value="([^"]+)"/', $response->getBody(), $tokenMatch);
        preg_match('/class="hc-trap"[^>]*>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*id="([^"]+)"/', $response->getBody(), $trapMatch);

        $this->assertNotEmpty($tokenMatch, 'Login page must render a human_check_token field');
        $this->assertNotEmpty($trapMatch, 'Login page must render the honeypot trap field');

        return [
            'human_check_token' => $tokenMatch[1],
            $trapMatch[1] => '', // trap left empty, as a human would
        ];
    }

    public function testHumanSubmissionAfterTheDelayIsAccepted(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();
        $fields = $this->humanCheckFields();

        $this->authService->expects($this->once())
            ->method('requestMagicLink')
            ->willReturn(new MagicLinkResult(true, 42, null));

        sleep(2);

        $body = array_merge(['email' => 'human@test.com', '_csrf_token' => $token, 'rgpd_consent' => '1'], $fields);
        $request = new Request('POST', '/login/magic-link', [], $body, [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $result = json_decode($response->getBody(), true);
        $this->assertTrue($result['success']);
    }

    public function testBotFillingTheHoneypotIsRejectedAndNeverReachesAuthService(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();
        $fields = $this->humanCheckFields();
        $trapFieldName = array_key_last($fields);
        $fields[$trapFieldName] = 'I am a bot filling every field';

        $this->authService->expects($this->never())->method('requestMagicLink');

        $body = array_merge(['email' => 'bot@test.com', '_csrf_token' => $token, 'rgpd_consent' => '1'], $fields);
        $request = new Request('POST', '/login/magic-link', [], $body, [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $result = json_decode($response->getBody(), true);
        $this->assertFalse($result['success']);
    }

    public function testBotSubmittingInstantlyIsRejectedAndNeverReachesAuthService(): void
    {
        $this->startTestSession();
        $token = CsrfGuard::generateToken();
        $fields = $this->humanCheckFields();

        $this->authService->expects($this->never())->method('requestMagicLink');

        // No sleep() — submitted the same instant the challenge was
        // rendered, well under the 2-second minimum delay.
        $body = array_merge(['email' => 'bot@test.com', '_csrf_token' => $token, 'rgpd_consent' => '1'], $fields);
        $request = new Request('POST', '/login/magic-link', [], $body, [], []);
        $response = $this->controller->requestMagicLink($request, []);

        $result = json_decode($response->getBody(), true);
        $this->assertFalse($result['success']);
    }
}
