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

        $sessionId = session_id();
        AuthSession::markAuthenticatedNow();
        $this->assertNull(AuthSession::authenticatedAt());
        $this->assertSame($sessionId, session_id());
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

    /**
     * Review of #1374: the time the session is re-issued at is the caller's —
     * the stamp of the write that authorised the refresh — and never later,
     * whenever the refresh happens to run.
     */
    public function testTheIssuedStampCanBeRefreshedAtTheTimeOfTheWriteThatAuthorisedIt(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $_SESSION['_auth']['issued_at'] = time() - 3600;
        $writeStamp = time() - 30;

        AuthSession::refreshIssuedAt($writeStamp);

        $this->assertSame($writeStamp, AuthSession::getIssuedAt());
    }

    /**
     * A fresh window rotates the session id, as a login does: a copy of
     * the old cookie must not share in the right to add a passkey or a
     * device credential. Everything held in the session stays.
     */
    public function testMarkingAuthenticatedNowRotatesTheIdAndRefreshesOnlyThatStamp(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $old = time() - 3600;
        $_SESSION['_auth']['issued_at'] = $old;
        $_SESSION['_auth']['authenticated_at'] = $old;
        $sessionId = session_id();

        AuthSession::markAuthenticatedNow();

        $this->assertNotSame($sessionId, session_id());
        $this->assertSame($old, AuthSession::getIssuedAt());
        $this->assertTrue(AuthSession::authenticatedWithin(600));
        $this->assertSame(42, AuthSession::getUserAccountId());
    }

    /**
     * The cookie's lifetime starts when the id last rotated. A password
     * change re-stamps 'issued_at' without a new cookie, so it must not
     * push the cookie's start forward.
     */
    public function testTheCookieStartIgnoresAPasswordChangeButFollowsARotation(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $old = time() - 3600;
        $_SESSION['_auth']['issued_at'] = $old;
        $_SESSION['_auth']['authenticated_at'] = $old;

        AuthSession::refreshIssuedAt();
        $this->assertSame($old, AuthSession::cookieIssuedAt());

        AuthSession::markAuthenticatedNow();
        $this->assertGreaterThan($old, AuthSession::cookieIssuedAt());
    }

    public function testACookieStartPredatingTheAuthenticationStampFallsBackToTheIssueStamp(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $_SESSION['_auth']['issued_at'] = 1_700_000_000;
        unset($_SESSION['_auth']['authenticated_at']);

        $this->assertSame(1_700_000_000, AuthSession::cookieIssuedAt());
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

    // ── A session another request destroyed or replaced (#1256) ──────────

    /**
     * **Every write that follows the early close re-reads the session it
     * reopens.** `public/index.php` closes the session at once to free its
     * lock: `$_SESSION` stays readable in memory, and the first write
     * reopens it. Writing back the copy taken BEFORE the reopening put an
     * identity the session no longer held into one that had been destroyed
     * or renewed by another request in between — with a fresh « connexion
     * récente » stamp, which is the right to add a passkey.
     *
     * @return iterable<string, array{0: \Closure(): void}>
     */
    public static function writesAfterTheEarlyClose(): iterable
    {
        yield 'markAuthenticatedNow' => [static fn() => AuthSession::markAuthenticatedNow()];
        yield 'setRole' => [static fn() => AuthSession::setRole('admin')];
        yield 'setLinkedMembers' => [static fn() => AuthSession::setLinkedMembers([7, 8])];
        yield 'refreshIssuedAt' => [static fn() => AuthSession::refreshIssuedAt()];
    }

    /**
     * @param \Closure(): void $write
     * @dataProvider writesAfterTheEarlyClose
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesAfterTheEarlyClose')]
    public function testAWriteNeverRevivesAnIdentityTheSessionNoLongerHolds(\Closure $write): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        // Signed out, or renewed, by another request: nothing is left.
        $this->anotherRequestRuns(static function (): void {
            $_SESSION = [];
        });

        $write();

        $this->assertArrayNotHasKey('_auth', $_SESSION, 'the copy this request took earlier was written back');
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertNull(AuthSession::authenticatedAt());
    }

    /**
     * @param \Closure(): void $write
     * @dataProvider writesAfterTheEarlyClose
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesAfterTheEarlyClose')]
    public function testAWriteNeverTouchesAnotherAccountsSession(\Closure $write): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $this->anotherRequestRuns(static function (): void {
            $_SESSION['_auth'] = [
                'user_account_id' => 99,
                'email' => 'autre@example.com',
                'role' => 'public',
                'issued_at' => 1_000,
                'authenticated_at' => 1_000,
                'generation' => null,
            ];
        });

        $write();

        $this->assertSame(99, AuthSession::getUserAccountId());
        $this->assertSame('public', AuthSession::getRole());
        $this->assertSame(1_000, AuthSession::authenticatedAt(), 'no fresh window for a session that is not ours');
        $this->assertSame(1_000, AuthSession::getIssuedAt());
        $this->assertSame([], AuthSession::getLinkedMembers());
    }

    /**
     * @param \Closure(): void $write
     * @dataProvider writesAfterTheEarlyClose
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesAfterTheEarlyClose')]
    public function testAWriteNeverTouchesTheSameAccountUnderAnotherGeneration(\Closure $write): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin', str_repeat('a', 64));
        $this->anotherRequestRuns(static function (): void {
            $_SESSION['_auth']['generation'] = str_repeat('b', 64);
            $_SESSION['_auth']['authenticated_at'] = 1_000;
            $_SESSION['_auth']['role'] = 'identified';
        });

        $write();

        $this->assertSame(str_repeat('b', 64), AuthSession::getGeneration());
        $this->assertSame(1_000, AuthSession::authenticatedAt());
        $this->assertSame('identified', AuthSession::getRole());
    }

    /** An abandoned write does not rotate the id either: nothing was proved. */
    public function testAnAbandonedFreshWindowDoesNotRotateTheSessionId(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $this->anotherRequestRuns(static function (): void {
            $_SESSION = [];
        });
        $id = session_id();

        AuthSession::markAuthenticatedNow();

        $this->assertSame($id, session_id());
    }

    /** The ordinary case is unchanged: the same session, reopened, takes the write. */
    public function testTheSameSessionReopenedStillTakesTheWrite(): void
    {
        $this->startTestSession();
        AuthSession::login(42, 'test@example.com', 'admin');
        $this->anotherRequestRuns(static function (): void {
            // Another request that touched nothing of ours.
        });

        AuthSession::setRole('chief');
        AuthSession::setLinkedMembers([7, 8]);
        AuthSession::refreshIssuedAt(2_000);

        $this->assertSame('chief', AuthSession::getRole());
        $this->assertSame([7, 8], AuthSession::getLinkedMembers());
        $this->assertSame(2_000, AuthSession::getIssuedAt());
    }

    /**
     * What `public/index.php` leaves behind after its early close, and what
     * another request did meanwhile: this request keeps the copy it read,
     * the stored session is whatever `$action` made of it.
     *
     * @param \Closure(): void $action
     */
    private function anotherRequestRuns(\Closure $action): void
    {
        $thisRequest = $_SESSION;
        $id = session_id();
        session_write_close();

        session_id($id);
        session_start();
        $action();
        session_write_close();

        $_SESSION = $thisRequest;
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
