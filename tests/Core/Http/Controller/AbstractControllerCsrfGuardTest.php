<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The single CSRF failure path (AbstractController::guardCsrf() /
 * guardCsrfJson()): one message, one shape, wherever the token travels
 * (parsed body field, raw $_POST, X-CSRF-Token header, or an explicitly
 * extracted token from a JSON payload).
 */
class AbstractControllerCsrfGuardTest extends TestCase
{
    private const TOKEN = 'test-csrf-token-0123456789abcdef';

    private AbstractController $controller;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = ['_csrf_token' => self::TOKEN];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);

        $twig = new Environment(new ArrayLoader([]));
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->controller = new class ($twig) extends AbstractController {
            public function callGuardCsrf(Request $request, string $redirectTo, ?string $token = null): ?Response
            {
                return $this->guardCsrf($request, $redirectTo, $token);
            }

            public function callGuardCsrfJson(Request $request, ?string $token = null): ?Response
            {
                return $this->guardCsrfJson($request, $token);
            }
        };
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    private function request(array $body = []): Request
    {
        return new Request('POST', '/x', [], $body, [], []);
    }

    public function testGuardCsrfReturnsNullWhenBodyTokenIsValid(): void
    {
        $result = $this->controller->callGuardCsrf($this->request(['_csrf_token' => self::TOKEN]), '/back');

        self::assertNull($result);
        self::assertNull(FlashMessage::get());
    }

    public function testGuardCsrfReturnsNullWhenExplicitTokenIsValid(): void
    {
        $result = $this->controller->callGuardCsrf($this->request(), '/back', self::TOKEN);

        self::assertNull($result);
    }

    public function testGuardCsrfReturnsNullWhenRawPostTokenIsValid(): void
    {
        // The CsrfGuard::validateRequest() transport most controllers used.
        $_POST['_csrf_token'] = self::TOKEN;

        self::assertNull($this->controller->callGuardCsrf($this->request(), '/back'));
    }

    public function testGuardCsrfReturnsNullWhenHeaderTokenIsValid(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = self::TOKEN;

        self::assertNull($this->controller->callGuardCsrf($this->request(), '/back'));
    }

    public function testGuardCsrfRedirectsWithTheOneMessageWhenTokenIsInvalid(): void
    {
        $result = $this->controller->callGuardCsrf($this->request(['_csrf_token' => 'stale']), '/retour');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(302, $result->getStatusCode());
        self::assertSame('/retour', $result->getHeaders()['Location'] ?? null);

        $flash = FlashMessage::get();
        self::assertNotNull($flash);
        self::assertSame('error', $flash['type']);
        self::assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, $flash['message']);
    }

    public function testGuardCsrfRedirectsWhenNoTokenAtAll(): void
    {
        $result = $this->controller->callGuardCsrf($this->request(), '/retour');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(302, $result->getStatusCode());
    }

    public function testGuardCsrfJsonReturnsNullWhenBodyTokenIsValid(): void
    {
        self::assertNull($this->controller->callGuardCsrfJson($this->request(['_csrf_token' => self::TOKEN])));
    }

    public function testGuardCsrfJsonReturnsNullWhenExplicitTokenIsValid(): void
    {
        // A token extracted from a JSON payload, the way AJAX endpoints pass it.
        self::assertNull($this->controller->callGuardCsrfJson($this->request(), self::TOKEN));
    }

    public function testGuardCsrfJsonAnswers403WithTheOneMessageWhenTokenIsInvalid(): void
    {
        $result = $this->controller->callGuardCsrfJson($this->request(), 'stale');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());

        $payload = json_decode($result->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        self::assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, $payload['error']);
        // No flash for JSON: the message travels in the response body only.
        self::assertNull(FlashMessage::get());
    }

    public function testAnInvalidExplicitTokenStillFailsWithNoOtherTransport(): void
    {
        // A wrong explicit token must never pass just because it was given.
        $result = $this->controller->callGuardCsrfJson($this->request(['_csrf_token' => 'also-stale']), 'stale');

        self::assertInstanceOf(Response::class, $result);
        self::assertSame(403, $result->getStatusCode());
    }

    public function testTheMessageTellsTheUserWhatToDo(): void
    {
        // The contract this refactor exists for: no jargon ("CSRF"), and an
        // actionable instruction, in French.
        self::assertSame(
            'Votre session a expiré. Rechargez la page et réessayez.',
            AbstractController::SESSION_EXPIRED_MESSAGE
        );
    }
}
