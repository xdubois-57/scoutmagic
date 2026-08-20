<?php

declare(strict_types=1);

namespace Tests\Core\Security;

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
        $this->revalidator = new SessionRevalidator($this->userRepo, $resolver);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnUntouchedSessionSurvives(): void
    {
        $account = $this->userRepo->create('member@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin');

        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
        $this->assertTrue(AuthSession::isAuthenticated());
        $this->assertSame('superadmin', AuthSession::getRole());
    }

    public function testNoSessionIsNothingToRevalidate(): void
    {
        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
        $this->assertFalse(AuthSession::isAuthenticated());
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
        AuthSession::login($account->id, $account->email, 'superadmin');
        $this->assertTrue(AuthSession::isAuthenticated());

        // The real owner resets their password a moment later.
        $_SESSION['_auth']['issued_at'] = time() - 60;
        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));

        $this->assertFalse($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
        AuthSession::login($account->id, $account->email, 'superadmin');

        $_SESSION['_auth']['issued_at'] = time() - 60;
        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));
        AuthSession::refreshIssuedAt();

        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
        AuthSession::login($account->id, $account->email, 'superadmin');
        unset($_SESSION['_auth']['issued_at']);

        // No password ever set → no stamp → nothing to revoke against.
        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
        $this->assertTrue(AuthSession::isAuthenticated());

        $this->userRepo->updatePasswordHash($account->id, password_hash('BrandNewPassword1!', PASSWORD_DEFAULT));
        unset($_SESSION['_auth']['issued_at']);

        $this->assertFalse($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    public function testASessionForADeletedAccountIsRevoked(): void
    {
        $account = $this->userRepo->create('ghost@test.com', true);
        AuthSession::login($account->id, $account->email, 'superadmin');

        $this->pdo->prepare('DELETE FROM user_accounts WHERE id = ?')->execute([$account->id]);

        $this->assertFalse($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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

        AuthSession::login($account->id, $account->email, 'chief');
        $this->assertSame('chief', AuthSession::getRole());

        // The function is taken away mid-session.
        $this->pdo->exec('DELETE FROM member_functions');

        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
        AuthSession::login($account->id, $account->email, 'identified');

        $this->pdo->exec("UPDATE functions SET role = 'admin' WHERE desk_code = 'Equipier'");

        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
        AuthSession::login($account->id, $account->email, 'chief');

        // They drop out of the unit entirely.
        $this->pdo->exec('DELETE FROM member_functions');
        $this->pdo->exec('DELETE FROM member_years');

        $this->assertFalse($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
        AuthSession::login($account->id, $account->email, 'superadmin');

        $this->assertTrue($this->revalidator->revalidate(fn(): int => $this->scoutYearId));
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
}
