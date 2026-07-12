<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\CsrfGuard;
use PHPUnit\Framework\TestCase;

class CsrfGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        // Suppress headers already sent warnings in tests
        @session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = CsrfGuard::generateToken();

        $this->assertNotEmpty($token);
        $this->assertIsString($token);
    }

    public function testGenerateTokenStoresTokenInSession(): void
    {
        $token = CsrfGuard::generateToken();

        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $token = CsrfGuard::generateToken();

        $this->assertTrue(CsrfGuard::validateToken($token));
    }

    public function testValidateTokenReturnsFalseForWrongToken(): void
    {
        CsrfGuard::generateToken();

        $this->assertFalse(CsrfGuard::validateToken('wrong_token'));
    }

    public function testValidateTokenReturnsFalseForNull(): void
    {
        CsrfGuard::generateToken();

        $this->assertFalse(CsrfGuard::validateToken(null));
    }

    public function testValidateTokenReturnsFalseForEmptyString(): void
    {
        CsrfGuard::generateToken();

        $this->assertFalse(CsrfGuard::validateToken(''));
    }

    public function testValidateRequestReturnsTrueFromPostBody(): void
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertTrue(CsrfGuard::validateRequest());

        unset($_POST['_csrf_token']);
    }

    public function testValidateRequestReturnsTrueFromHeader(): void
    {
        $token = CsrfGuard::generateToken();
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(CsrfGuard::validateRequest());

        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testValidateRequestReturnsFalseWithNoTokenAnywhere(): void
    {
        CsrfGuard::generateToken();
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertFalse(CsrfGuard::validateRequest());
    }

    public function testValidateRequestPrefersBodyOverHeader(): void
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong';

        $this->assertTrue(CsrfGuard::validateRequest());

        unset($_POST['_csrf_token']);
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }
}
