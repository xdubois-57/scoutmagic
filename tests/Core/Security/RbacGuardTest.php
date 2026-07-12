<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class RbacGuardTest extends TestCase
{
    private RbacGuard $guard;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->guard = new RbacGuard();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCheckReturnsTrueWhenRoleIsSufficient(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->assertTrue($this->guard->check(Role::ADMIN));
        $this->assertTrue($this->guard->check(Role::PUBLIC));
    }

    public function testCheckReturnsFalseWhenRoleIsInsufficient(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $this->assertFalse($this->guard->check(Role::ADMIN));
        $this->assertFalse($this->guard->check(Role::CHIEF));
    }

    public function testEnforceReturnsNullWhenAccessGranted(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');

        $this->assertNull($this->guard->enforce(Role::ADMIN));
        $this->assertNull($this->guard->enforce(Role::PUBLIC));
    }

    public function testEnforceRedirectsToLoginWhenNotAuthenticated(): void
    {
        $this->startTestSession();
        // No login — user is unauthenticated

        $response = $this->guard->enforce(Role::IDENTIFIED);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('/login', $headers['Location']);
    }

    public function testEnforceReturns403WhenAuthenticatedButInsufficient(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'user@test.com', 'identified');

        $response = $this->guard->enforce(Role::ADMIN);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEnforceReturnsNullForPublicRouteWithoutAuth(): void
    {
        $this->startTestSession();

        $this->assertNull($this->guard->enforce(Role::PUBLIC));
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
