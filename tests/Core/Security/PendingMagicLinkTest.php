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
}
