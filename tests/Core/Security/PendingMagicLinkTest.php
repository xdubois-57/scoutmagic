<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\PendingMagicLink;
use PHPUnit\Framework\TestCase;

class PendingMagicLinkTest extends TestCase
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

    public function testMatchesOnlyTheRememberedId(): void
    {
        PendingMagicLink::remember(42);

        $this->assertTrue(PendingMagicLink::matches(42));
        $this->assertFalse(PendingMagicLink::matches(41));
        $this->assertFalse(PendingMagicLink::matches(43));
    }

    public function testMatchesNothingWhenNoLinkWasRequested(): void
    {
        $this->assertFalse(PendingMagicLink::matches(1));
        $this->assertFalse(PendingMagicLink::matches(0));
    }

    /**
     * A non-positive id must never match, including the 0 that
     * AuthController's `(int) $params['id']` yields for a missing or
     * non-numeric path segment.
     */
    public function testNonPositiveIdsNeverMatch(): void
    {
        PendingMagicLink::remember(0);
        $this->assertFalse(PendingMagicLink::matches(0));

        PendingMagicLink::remember(-1);
        $this->assertFalse(PendingMagicLink::matches(-1));
    }

    public function testRememberingAgainReplacesThePreviousId(): void
    {
        PendingMagicLink::remember(7);
        PendingMagicLink::remember(8);

        $this->assertFalse(PendingMagicLink::matches(7));
        $this->assertTrue(PendingMagicLink::matches(8));
    }

    public function testForgetDropsThePendingId(): void
    {
        PendingMagicLink::remember(9);
        PendingMagicLink::forget();

        $this->assertFalse(PendingMagicLink::matches(9));
    }

    /**
     * The id lives in the session, so a different session (a different
     * visitor) inherits nothing — which is the whole point.
     */
    public function testAnotherSessionDoesNotInheritThePendingId(): void
    {
        PendingMagicLink::remember(11);
        $this->assertTrue(PendingMagicLink::matches(11));

        $_SESSION = [];

        $this->assertFalse(PendingMagicLink::matches(11));
    }

    /**
     * Issue #1115: the poll token is the page's, not the session's — fresh
     * on every remember(), never stored in clear, matched exactly.
     */
    public function testThePollTokenMatchesOnlyItselfAndIsStoredHashed(): void
    {
        $token = PendingMagicLink::remember(12);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(PendingMagicLink::pollTokenMatches($token));
        $this->assertFalse(PendingMagicLink::pollTokenMatches(strtoupper($token)));
        $this->assertFalse(PendingMagicLink::pollTokenMatches(substr($token, 1)));
        $this->assertFalse(PendingMagicLink::pollTokenMatches(''));
        $this->assertFalse(PendingMagicLink::pollTokenMatches(null));
        $this->assertStringNotContainsString($token, serialize($_SESSION));
    }

    public function testEachRememberMintsANewTokenAndRetiresTheOld(): void
    {
        $first = PendingMagicLink::remember(13);
        $second = PendingMagicLink::remember(14);

        $this->assertNotSame($first, $second);
        $this->assertFalse(PendingMagicLink::pollTokenMatches($first));
        $this->assertTrue(PendingMagicLink::pollTokenMatches($second));
    }

    public function testForgetRetiresThePollToken(): void
    {
        $token = PendingMagicLink::remember(15);
        PendingMagicLink::forget();

        $this->assertFalse(PendingMagicLink::pollTokenMatches($token));
    }

    /**
     * A pending link stored before the token existed has none: no value
     * matches it, and that page asks for a new link.
     */
    public function testAPendingLinkWithoutATokenMatchesNoToken(): void
    {
        $_SESSION['_pending_magic_link'] = 16;

        $this->assertFalse(PendingMagicLink::pollTokenMatches(hash('sha256', '')));
        $this->assertFalse(PendingMagicLink::pollTokenMatches('anything'));
    }
}
