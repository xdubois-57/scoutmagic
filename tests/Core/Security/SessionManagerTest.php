<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testStartStartsASession(): void
    {
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());

        @SessionManager::start();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testIsActiveReturnsCorrectState(): void
    {
        $this->assertFalse(SessionManager::isActive());

        @SessionManager::start();

        $this->assertTrue(SessionManager::isActive());
    }

    public function testStartDoesNotRestartActiveSession(): void
    {
        @SessionManager::start();
        $sessionId = session_id();

        @SessionManager::start();

        $this->assertSame($sessionId, session_id());
    }

    public function testStartConfiguresALongLivedCookieForInstalledPwaSessions(): void
    {
        @SessionManager::start();

        $thirtyDaysInSeconds = 30 * 24 * 60 * 60;
        $this->assertSame((string) $thirtyDaysInSeconds, ini_get('session.cookie_lifetime'));
        $this->assertSame((string) $thirtyDaysInSeconds, ini_get('session.gc_maxlifetime'));
    }

    public function testCookieExpiryCountsFromLoginNotFromTheCurrentVisit(): void
    {
        // Logged in 29 days ago: the cookie ends tomorrow, not 30 days from now.
        $now = 1_800_000_000;
        $issuedAt = $now - 29 * 86400;

        $this->assertSame(
            $issuedAt + SessionManager::COOKIE_LIFETIME_SECONDS,
            SessionManager::cookieExpiresAt($issuedAt, $now)
        );
    }

    public function testCookieExpiryFallsBackToNowWhenTheIssueTimeIsUnknownOrInTheFuture(): void
    {
        $now = 1_800_000_000;

        $expected = $now + SessionManager::COOKIE_LIFETIME_SECONDS;
        $this->assertSame($expected, SessionManager::cookieExpiresAt(null, $now));
        $this->assertSame($expected, SessionManager::cookieExpiresAt($now + 500, $now));
    }
}
