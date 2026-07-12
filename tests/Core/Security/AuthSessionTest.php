<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;

class AuthSessionTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Reset session data
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testLoginStoresUserDataInSession(): void
    {
        $this->startTestSession();

        AuthSession::login(42, 'test@example.com', 'admin');

        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame(42, AuthSession::getUserAccountId());
        $this->assertSame('test@example.com', AuthSession::getEmail());
        $this->assertSame('admin', AuthSession::getRole());
    }

    public function testLogoutClearsAuthData(): void
    {
        $this->startTestSession();

        AuthSession::login(42, 'test@example.com', 'admin');
        $this->assertTrue(AuthSession::isAuthenticated());

        AuthSession::logout();

        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertNull(AuthSession::getUserAccountId());
        $this->assertNull(AuthSession::getEmail());
        $this->assertSame('public', AuthSession::getRole());
    }

    public function testIsAuthenticatedReturnsFalseWhenNotLoggedIn(): void
    {
        $this->startTestSession();

        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testGetRoleReturnsPublicWhenNotAuthenticated(): void
    {
        $this->startTestSession();

        $this->assertSame('public', AuthSession::getRole());
    }

    public function testGetUserAccountIdReturnsNullWhenNotAuthenticated(): void
    {
        $this->startTestSession();

        $this->assertNull(AuthSession::getUserAccountId());
    }

    public function testGetEmailReturnsNullWhenNotAuthenticated(): void
    {
        $this->startTestSession();

        $this->assertNull(AuthSession::getEmail());
    }

    public function testLoginRegeneratesSessionId(): void
    {
        $this->startTestSession();
        $oldId = session_id();

        AuthSession::login(1, 'a@b.com', 'identified');
        $newId = session_id();

        $this->assertNotSame($oldId, $newId);
    }

    public function testLogoutRegeneratesSessionId(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'a@b.com', 'identified');
        $oldId = session_id();

        AuthSession::logout();
        $newId = session_id();

        $this->assertNotSame($oldId, $newId);
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }
}
