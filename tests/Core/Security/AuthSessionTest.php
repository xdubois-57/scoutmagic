<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\AuthSession;
use Core\Security\RecentAuthentication;
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

    // --- when the session last proved who it is (issue #1115) ----------

    public function testLoginRecordsTheMomentOfAuthentication(): void
    {
        $this->startTestSession();
        $before = time();

        AuthSession::login(42, 'test@example.com', 'admin');

        $this->assertNotNull(AuthSession::authenticatedAt());
        $this->assertGreaterThanOrEqual($before, AuthSession::authenticatedAt());
        $this->assertSame(AuthSession::getIssuedAt(), AuthSession::authenticatedAt());
    }

    public function testAuthenticatedWithinIsInclusiveAtTheBoundaryAndFalsePastIt(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $at = (int) AuthSession::authenticatedAt();

        $this->assertTrue(AuthSession::authenticatedWithin(600, $at));
        $this->assertTrue(AuthSession::authenticatedWithin(600, $at + 599));
        $this->assertTrue(AuthSession::authenticatedWithin(600, $at + 600));
        $this->assertFalse(AuthSession::authenticatedWithin(600, $at + 601));
    }

    public function testAStampInTheFutureProvesNothing(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $at = (int) AuthSession::authenticatedAt();

        $this->assertFalse(AuthSession::authenticatedWithin(600, $at - 1));
    }

    public function testASessionPredatingTheFieldIsNeverRecent(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        unset($_SESSION['_auth']['authenticated_at']);

        $this->assertNull(AuthSession::authenticatedAt());
        $this->assertFalse(AuthSession::authenticatedWithin(600));
    }

    public function testNobodySignedInIsNeverRecent(): void
    {
        $this->startTestSession();

        $this->assertNull(AuthSession::authenticatedAt());
        $this->assertFalse(AuthSession::authenticatedWithin(600));

        AuthSession::markAuthenticatedNow();
        $this->assertNull(AuthSession::authenticatedAt());
    }

    /**
     * refreshIssuedAt() keeps the tab signed in after a password change;
     * it is not a proof of identity (a FIRST password proves nothing), so
     * it leaves the last authentication where it was.
     */
    public function testRefreshingTheIssuedStampIsNotAFreshAuthentication(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $old = time() - 3600;
        $_SESSION['_auth']['issued_at'] = $old;
        $_SESSION['_auth']['authenticated_at'] = $old;

        AuthSession::refreshIssuedAt();

        $this->assertGreaterThan($old, AuthSession::getIssuedAt());
        $this->assertSame($old, AuthSession::authenticatedAt());
        $this->assertFalse(AuthSession::authenticatedWithin(600));
    }

    public function testMarkingAuthenticatedNowKeepsTheSessionAndRefreshesOnlyThatStamp(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $old = time() - 3600;
        $_SESSION['_auth']['issued_at'] = $old;
        $_SESSION['_auth']['authenticated_at'] = $old;
        $sessionId = session_id();

        AuthSession::markAuthenticatedNow();

        $this->assertSame($sessionId, session_id());
        $this->assertSame($old, AuthSession::getIssuedAt());
        $this->assertTrue(AuthSession::authenticatedWithin(600));
        $this->assertSame(42, AuthSession::getUserAccountId());
    }

    public function testRecentAuthenticationUsesTheTenMinuteWindow(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $at = (int) AuthSession::authenticatedAt();

        $this->assertSame(600, RecentAuthentication::WINDOW_SECONDS);
        $this->assertTrue(RecentAuthentication::isRecent($at + 600));
        $this->assertFalse(RecentAuthentication::isRecent($at + 601));
    }

    public function testTheReauthenticationLinkKeepsOnlyASafeReturnPath(): void
    {
        $this->assertSame('/login?return=%2Faccount&reauth=1', RecentAuthentication::loginUrl('/account'));
        $this->assertSame('/login?reauth=1', RecentAuthentication::loginUrl('https://evil.example/'));
        $this->assertSame('/login?reauth=1', RecentAuthentication::loginUrl('//evil.example/'));
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
