<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\LoginReturn;
use PHPUnit\Framework\TestCase;

/**
 * Issue #833: a login goes back to the page that asked for it, and only
 * ever to a page of this site.
 */
class LoginReturnTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testNothingRememberedGoesHome(): void
    {
        $this->assertSame('/', LoginReturn::target());
        $this->assertSame('/', LoginReturn::consume());
    }

    public function testAnInternalPathIsKeptUntilALoginSpendsIt(): void
    {
        LoginReturn::remember('/r/abc123');

        $this->assertSame('/r/abc123', LoginReturn::target());
        $this->assertSame('/r/abc123', LoginReturn::target(), 'Reading it does not spend it.');
        $this->assertSame('/r/abc123', LoginReturn::consume());
        $this->assertSame('/', LoginReturn::consume(), 'A login spends it once.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'another site' => ['https://evil.example/r/abc'],
            'scheme-relative' => ['//evil.example'],
            'backslash' => ['/\\evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'relative' => ['r/abc'],
            'double slash inside' => ['/r//evil.example'],
            'line break' => ["/r/abc\r\nSet-Cookie: x=1"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testAnythingButAnInternalPathIsNeverKept(string $raw): void
    {
        LoginReturn::remember($raw);

        $this->assertSame('/', LoginReturn::consume());
    }

    public function testAPlainLoginPageForgetsAnEarlierReturn(): void
    {
        LoginReturn::remember('/r/abc123');
        LoginReturn::remember('');

        $this->assertSame('/', LoginReturn::target());
    }

    public function testARefusedValueForgetsAnEarlierReturnToo(): void
    {
        LoginReturn::remember('/r/abc123');
        LoginReturn::remember('https://evil.example');

        $this->assertSame('/', LoginReturn::target());
    }

    public function testAValuePlantedInTheSessionIsCheckedOnTheWayOut(): void
    {
        $_SESSION['_login_return'] = '//evil.example';

        $this->assertSame('/', LoginReturn::target());
    }

    public function testTheLoginUrlCarriesTheReturnPathEncoded(): void
    {
        $this->assertSame('/login?return=%2Fr%2Fabc%3Fx%3D1', LoginReturn::loginUrl('/r/abc?x=1'));
        $this->assertSame('/login', LoginReturn::loginUrl('/'));
        $this->assertSame('/login', LoginReturn::loginUrl('https://evil.example'));
    }
}
