<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\ScoutYear\AuthorizationYears;
use Core\Import\MemberYearRepository;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\SessionRevalidator;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SessionRevalidatorTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $userRepo;
    private SessionRevalidator $revalidator;
    private int $scoutYearId;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $resolver = new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo);
        $this->revalidator = new SessionRevalidator($this->userRepo, $resolver, \Tests\SessionGenerationFixture::forPdo($this->pdo));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * One year, and it is the public one — the shape of every day of the
     * year outside a transition. The set that actually has two years in it
     * is exercised by Tests\Integration\ScoutYearTransitionAccessTest,
     * which is where the A/B scenario lives.
     */
    private function authorizationYears(): AuthorizationYears
    {
        return new AuthorizationYears($this->scoutYearId, [$this->scoutYearId]);
    }

    public function testAnUntouchedSessionSurvives(): void
    {
        $account = $this->userRepo->create('member@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('superadmin', AuthSession::getRole());
    }

    public function testNoSessionIsNothingToRevalidate(): void
    {
        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testAReusedAccountIdCannotChangeTheAuthenticatedIdentity(): void
    {
        AuthSession::login(1, 'previous-installation@example.com', 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());
        $replacement = $this->userRepo->create('new-installation@example.com', true);
        $this->assertSame(1, $replacement->id);

        $survived = $this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears());

        $this->assertSame([false, false, null], [
            $survived,
            AuthSession::isAuthenticated(),
            AuthSession::getUserAccountId(),
        ]);
    }

    public function testEveryOldBrowserIsRejectedEvenWhenTheEmailAndIdAreReused(): void
    {
        $account = $this->userRepo->create('same@example.com', true);
        $generation = \Tests\SessionGenerationFixture::forPdo($this->pdo);
        AuthSession::login($account->id, $account->email, 'superadmin', $generation->current());
        $firstBrowser = $_SESSION;
        AuthSession::login($account->id, $account->email, 'superadmin', $generation->current());
        $secondBrowser = $_SESSION;

        $generation->rotate();
        $results = [];
        foreach ([$firstBrowser, $secondBrowser] as $browser) {
            $_SESSION = $browser;
            $results[] = [
                $this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()),
                AuthSession::getEmail(),
            ];
        }
        AuthSession::login($account->id, $account->email, 'superadmin', $generation->current());
        $results[] = [
            $this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()),
            AuthSession::getEmail(),
        ];

        $this->assertSame([[false, null], [false, null], [true, 'same@example.com']], $results);
    }

    public function testEquivalentEmailCaseAndWhitespaceDoNotChangeTheIdentity(): void
    {
        $account = $this->userRepo->create('same@example.com', true);
        AuthSession::login($account->id, ' SAME@EXAMPLE.COM ', 'superadmin',
            \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidGenerations')]
    public function testLegacyAndMalformedSessionsAreRevoked(mixed $generation): void
    {
        $account = $this->userRepo->create('same@example.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin');
        $_SESSION['_auth']['generation'] = $generation;

        $this->assertSame([false, false], [
            $this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()),
            AuthSession::isAuthenticated(),
        ]);
    }

    public static function invalidGenerations(): iterable
    {
        yield 'legacy' => [null];
        yield 'wrong type' => [[]];
        yield 'truncated' => ['abc'];
        yield 'different installation' => [str_repeat('f', 64)];
    }

    /**
     * The point of the whole mechanism: a password reset is supposed to
     * recover a compromised account, but an attacker's existing session
     * used to survive it untouched — PHP's file-based sessions give no way
     * to enumerate and destroy another session directly.
     */
    public function testASessionIssuedBeforeAPasswordChangeIsRevoked(): void
    {
        $account = $this->userRepo->create('victim@test.com', true);

        // The attacker's session, established before the reset.
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());
        $this->assertTrue(AuthSession::isAuthenticated());

        // The real owner resets their password a moment later.
        $_SESSION['_auth']['issued_at'] = time() - 60;
        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));

        $this->assertFalse($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
        $this->assertNull(AuthSession::getUserAccountId());
    }

    /**
     * The member who performed the change keeps the tab they did it in —
     * AccountController re-stamps this session straight after the write.
     */
    public function testASessionRestampedAfterThePasswordChangeSurvives(): void
    {
        $account = $this->userRepo->create('owner@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $_SESSION['_auth']['issued_at'] = time() - 60;
        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));
        AuthSession::refreshIssuedAt();

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    /**
     * A session predating the revocation mechanism can't prove it is newer
     * than the stamp, so it loses the benefit of the doubt — but only once
     * the account actually carries a stamp.
     */
    public function testASessionWithNoIssueTimestampIsRevokedOnlyOnceAStampExists(): void
    {
        $account = $this->userRepo->create('legacy@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());
        unset($_SESSION['_auth']['issued_at']);

        // No password ever set → no stamp → nothing to revoke against.
        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());

        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));
        unset($_SESSION['_auth']['issued_at']);

        $this->assertFalse($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testASessionForADeletedAccountIsRevoked(): void
    {
        $account = $this->userRepo->create('ghost@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->pdo->prepare('DELETE FROM user_accounts WHERE id = ?')->execute([$account->id]);

        $this->assertFalse($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    /**
     * Role staleness: the role was decided once at login and then trusted
     * for up to the 30-day session lifetime, so losing a function didn't
     * actually reduce anyone's access until they next signed in.
     */
    public function testADemotionTakesEffectOnTheNextRequest(): void
    {
        $this->createMemberWithFunction('chief@test.com', 'Animateur', 'chief');
        $account = $this->userRepo->create('chief@test.com');

        AuthSession::login($account->id, $account->email, 'chief', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());
        $this->assertSame('chief', AuthSession::getRole());

        // The function is taken away mid-session.
        $this->pdo->exec('DELETE FROM member_functions');

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('identified', AuthSession::getRole());
    }

    /**
     * And the same machinery must not strand a promotion either.
     */
    public function testAPromotionAlsoTakesEffectOnTheNextRequest(): void
    {
        $this->createMemberWithFunction('rising@test.com', 'Equipier', 'identified');
        $account = $this->userRepo->create('rising@test.com');
        AuthSession::login($account->id, $account->email, 'identified', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->pdo->exec("UPDATE functions SET role = 'admin' WHERE desk_code = 'Equipier'");

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertSame('admin', AuthSession::getRole());
    }

    /**
     * The login gate refuses an address that matches no member this year;
     * an existing session must not outlive that gate by up to 30 days.
     */
    public function testASessionForSomeoneNoLongerAMemberIsRevoked(): void
    {
        $this->createMemberWithFunction('leaver@test.com', 'Animateur', 'chief');
        $account = $this->userRepo->create('leaver@test.com');
        AuthSession::login($account->id, $account->email, 'chief', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        // They drop out of the unit entirely.
        $this->pdo->exec('DELETE FROM member_functions');
        $this->pdo->exec('DELETE FROM member_years');

        $this->assertFalse($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    /**
     * Super-admins run the site and are not necessarily Desk members, so
     * the membership check must never lock them out (same carve-out
     * RoleResolver::isEmailAuthorizedToLogin() makes at login).
     */
    public function testASuperAdminWithNoMemberRecordIsNotRevoked(): void
    {
        $account = $this->userRepo->create('operator@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('superadmin', AuthSession::getRole());
    }

    private function createMemberWithFunction(string $email, string $functionCode, string $role): void
    {
        $normalizedEmail = strtolower($email);
        $blindIndex = $this->encryption->blindIndex($normalizedEmail, 'email');

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('desk_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$functionCode, $functionCode, $role]);
        $fnStmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $fnStmt->execute([$functionCode]);
        $functionId = (int) $fnStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Test', 'member_years.first_name'), $this->encryption->encrypt('User', 'member_years.last_name'),
            $this->encryption->encrypt($normalizedEmail, 'member_years.email'), $blindIndex,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);
    }

    /**
     * A deactivation that only closed the door for the NEXT login would
     * leave the account fully usable in whatever tab is already open, for
     * up to the 30 days of the session cookie. deactivate() stamps
     * sessions_valid_from precisely so it does not.
     */
    public function testDeactivatingAnAccountDropsItsLiveSessionOnTheNextRequest(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());
        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));

        $this->userRepo->deactivate($account->id);

        $this->assertFalse($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testAnActiveAccountsSessionIsUnaffectedByTheNewColumn(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
    }

    /**
     * Reactivation deliberately leaves sessions_valid_from alone, so the
     * sessions the deactivation revoked stay revoked — but a session
     * opened afterwards works normally.
     */
    public function testASessionOpenedAfterReactivationSurvives(): void
    {
        $account = $this->userRepo->create('admin@test.com', true);
        $this->userRepo->deactivate($account->id);
        $this->userRepo->reactivate($account->id);

        AuthSession::login($account->id, $account->email, 'superadmin', \Tests\SessionGenerationFixture::forPdo($this->pdo)->current());

        $this->assertTrue($this->revalidator->revalidate(fn(): AuthorizationYears => $this->authorizationYears()));
        $this->assertTrue(AuthSession::isAuthenticated());
    }
}
