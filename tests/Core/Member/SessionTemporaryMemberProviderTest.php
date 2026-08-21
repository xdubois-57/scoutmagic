<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\SessionTemporaryMemberProvider;
use Core\Member\TemporaryMemberSession;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;

/**
 * The two guards the session-backed provider re-evaluates on every call
 * (ARCHITECTURE.md §8.42): the caller must still be an admin, and the
 * override only ever applies to the account that set it.
 */
class SessionTemporaryMemberProviderTest extends TestCase
{
    private SessionTemporaryMemberProvider $provider;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            session_start();
        }
        $_SESSION = [];
        $this->provider = new SessionTemporaryMemberProvider();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testResolvesForTheAdminThatSetIt(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);

        $this->assertSame(42, $this->provider->resolveMemberYearId('admin@test.be'));
    }

    public function testEmailComparisonIsCaseAndWhitespaceInsensitive(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);

        $this->assertSame(42, $this->provider->resolveMemberYearId('  Admin@Test.BE '));
    }

    public function testReturnsNullWhenNoOverrideIsSet(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->assertNull($this->provider->resolveMemberYearId('admin@test.be'));
    }

    public function testReturnsNullForAnotherAccountsEmail(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);

        $this->assertNull($this->provider->resolveMemberYearId('someone.else@test.be'));
    }

    public function testReturnsNullOnceTheSessionIsNoLongerAdmin(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);

        // A demotion mid-session (a Desk import, say) revokes the override
        // immediately, without waiting for a logout or an explicit purge.
        AuthSession::setRole('chief');

        $this->assertNull($this->provider->resolveMemberYearId('admin@test.be'));
    }

    public function testReturnsNullWhenNotAuthenticatedAtAll(): void
    {
        TemporaryMemberSession::set(42);

        $this->assertNull($this->provider->resolveMemberYearId('admin@test.be'));
    }

    public function testClearRemovesTheOverride(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);
        TemporaryMemberSession::clear();

        $this->assertNull(TemporaryMemberSession::get());
        $this->assertNull($this->provider->resolveMemberYearId('admin@test.be'));
    }

    public function testSetReplacesThePreviousMember(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        TemporaryMemberSession::set(42);
        TemporaryMemberSession::set(43);

        $this->assertSame(43, TemporaryMemberSession::get());
    }
}
