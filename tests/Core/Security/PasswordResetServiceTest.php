<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\Security\PasswordPolicy;
use Core\Security\PasswordResetService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Mirrors Tests\Core\Security\AuthServiceTest's structure/conventions for
 * the "mot de passe oublié" flow. Unlike MagicLinkRepository,
 * Core\Security\PasswordResetRepository never uses MySQL-only NOW()/
 * DATE_SUB(), so this suite (uniquely, compared to AuthServiceTest) runs
 * cleanly against the SQLite test database with no portability failures.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PasswordResetServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private PasswordResetService $service;
    private UserAccountRepository $userRepo;
    private bool $emailSent;
    private string $capturedResetUrl = '';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);

        $this->emailSent = false;
        $mailService = $this->createStub(\Core\Mail\MailService::class);
        $mailService->method('send')->willReturnCallback(function (string $to, string $subject, string $bodyHtml, string $bodyText) {
            $this->emailSent = true;
            $this->capturedResetUrl = trim($bodyText);
        });

        $twig = new Environment(new ArrayLoader([
            'email/password_reset.html.twig' => '<a href="{{ reset_url }}">Reset</a>',
            'email/password_reset.text.twig' => '{{ reset_url }}',
        ]));
        $twig->addGlobal('site_name', 'Test Unit');

        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->service = new PasswordResetService(
            $connection, $this->encryption, $mailService, EmailTemplateRendererFactory::overTestDatabase($this->pdo, $twig), 'https://example.com', 'Test Unit'
        );
    }

    public function testRequestResetWithKnownEmailSendsEmail(): void
    {
        $this->userRepo->create('user@test.com');

        $this->service->requestReset('user@test.com');

        $this->assertTrue($this->emailSent);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testRequestResetWithUnknownEmailDoesNotSendEmailButStillCreatesToken(): void
    {
        // No enumeration: a token row is still created (so rate limiting is
        // consistent either way) but no email goes out.
        $this->service->requestReset('unknown@test.com');

        $this->assertFalse($this->emailSent);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testRequestResetRateLimiting(): void
    {
        $this->userRepo->create('limited@test.com');

        for ($i = 0; $i < 5; $i++) {
            $this->service->requestReset('limited@test.com');
        }
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());

        // 6th request is silently dropped (no new row, no email).
        $this->emailSent = false;
        $this->service->requestReset('limited@test.com');
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        $this->assertFalse($this->emailSent);
    }

    /**
     * A reset e-mail that cannot leave answers exactly like an address with
     * no account: no exception, the token row, the journal line — so the
     * failure tells a visitor nothing about whether the account exists.
     */
    public function testAFailedSendAnswersExactlyLikeAnUnknownAddress(): void
    {
        $this->userRepo->create('known@test.com');
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())
            ->method('send')
            ->with('known@test.com')
            ->willThrowException(new MailException('SMTP connect() failed'));
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $service = new PasswordResetService(
            $connection,
            $this->encryption,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, TestTwig::create()),
            'https://example.com',
            'Test Unit'
        );
        $service->setJournalService(new JournalService(new JournalRepository($this->pdo)));

        $service->requestReset('known@test.com');
        $service->requestReset('unknown@test.com');

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        $this->assertSame(
            ['password_reset_requested', 'password_reset_requested'],
            $this->pdo->query('SELECT event_type FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testCheckTokenValidForFreshToken(): void
    {
        $this->userRepo->create('checker@test.com');
        [$id, $rawToken] = $this->createRawToken('checker@test.com');

        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testCheckTokenInvalidForWrongToken(): void
    {
        $this->userRepo->create('checker2@test.com');
        [$id] = $this->createRawToken('checker2@test.com');

        $this->assertFalse($this->service->checkToken($id, 'wrong-token'));
    }

    public function testCheckTokenInvalidForExpiredToken(): void
    {
        $this->userRepo->create('expired@test.com');
        [$id, $rawToken] = $this->createRawToken('expired@test.com', expired: true);

        $this->assertFalse($this->service->checkToken($id, $rawToken));
    }

    public function testCheckTokenDoesNotConsumeIt(): void
    {
        $this->userRepo->create('viewer@test.com');
        [$id, $rawToken] = $this->createRawToken('viewer@test.com');

        $this->service->checkToken($id, $rawToken);
        // Still valid on a second look — viewing the page must never burn the token.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testResetPasswordSucceedsAndUpdatesHashAndTimestamp(): void
    {
        $account = $this->userRepo->create('reset@test.com');
        [$id, $rawToken] = $this->createRawToken('reset@test.com');

        $result = $this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1');

        $this->assertTrue($result);
        $updated = $this->userRepo->findById($account->id);
        $this->assertTrue(password_verify('MyNewSecureP@ss1', $updated->passwordHash));
        $this->assertNotNull($updated->passwordChangedAt);
    }

    /**
     * Review of #1374, through the service a reset link goes through: a
     * « Déconnecter partout » stamped the account's sessions, dated ahead; the
     * reset that follows — in the same second, whatever the clock says — must not
     * bring that stamp back to « now », or a session it had revoked would read as
     * valid again. The stamp is fixed in the future, so « now » is always earlier.
     */
    public function testResetPasswordLeavesALaterRevocationStampWhereItIs(): void
    {
        $account = $this->userRepo->create('stamped@test.com');
        $ahead = date('Y-m-d H:i:s', time() + 3600);
        $set = $this->pdo->prepare('UPDATE user_accounts SET sessions_valid_from = ? WHERE id = ?');
        $set->execute([$ahead, $account->id]);
        [$id, $rawToken] = $this->createRawToken('stamped@test.com');

        $this->assertTrue($this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1'));

        $reloaded = $this->userRepo->findById($account->id);
        $this->assertTrue(password_verify('MyNewSecureP@ss1', (string) $reloaded?->passwordHash));
        $this->assertSame($ahead, $reloaded?->sessionsValidFrom?->format('Y-m-d H:i:s'));
    }

    /**
     * Review of #1374, the three steps in one sequence with the real services:
     * a session open for the account, a « Déconnecter partout » whose stamp is
     * dated one second ahead of it, a reset link used right after, and the old
     * session revalidated — which must still be revoked. The instants are fixed
     * (the session and the stamp are in the future, so the reset's « now » is
     * always earlier than the sign-out's stamp), not read from the clock.
     */
    public function testASessionRevokedByASignOutEverywhereStaysRevokedAfterAReset(): void
    {
        $account = $this->userRepo->create('sequence@test.com', true);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $yearId = (int) $this->pdo->lastInsertId();
        $revalidator = new \Core\Security\SessionRevalidator(
            $this->userRepo,
            new \Core\Security\RoleResolver(new \Core\Import\MemberYearRepository($this->pdo), $this->encryption, $this->pdo),
            \Tests\SessionGenerationFixture::forPdo($this->pdo)
        );
        $_SESSION = [];
        \Core\Security\AuthSession::login(
            $account->id,
            $account->email,
            'superadmin',
            \Tests\SessionGenerationFixture::forPdo($this->pdo)->current()
        );
        $issuedAt = time() + 1000;
        $_SESSION['_auth']['issued_at'] = $issuedAt;

        // « Déconnecter partout », whose stamp is the second after the session's.
        $signOutStamp = date('Y-m-d H:i:s', $issuedAt + 1);
        $set = $this->pdo->prepare('UPDATE user_accounts SET sessions_valid_from = ? WHERE id = ?');
        $set->execute([$signOutStamp, $account->id]);

        // The reset link, used right after.
        [$id, $rawToken] = $this->createRawToken('sequence@test.com');
        $this->assertTrue($this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1'));

        $this->assertSame($signOutStamp, $this->userRepo->findById($account->id)?->sessionsValidFrom?->format('Y-m-d H:i:s'));
        $this->assertFalse($revalidator->revalidate(
            fn(): \Core\ScoutYear\AuthorizationYears => new \Core\ScoutYear\AuthorizationYears($yearId, [$yearId])
        ));
        $this->assertFalse(\Core\Security\AuthSession::isAuthenticated(), 'the old session stays revoked');
    }

    public function testResetPasswordConsumesTokenSoItCannotBeReused(): void
    {
        $this->userRepo->create('onceonly@test.com');
        [$id, $rawToken] = $this->createRawToken('onceonly@test.com');

        $this->assertTrue($this->service->resetPassword($id, $rawToken, 'FirstNewP@ss1'));
        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'SecondNewP@ss1'));
    }

    public function testResetPasswordRejectsPasswordFailingComplexityPolicy(): void
    {
        $this->userRepo->create('weak@test.com');
        [$id, $rawToken] = $this->createRawToken('weak@test.com');

        $this->assertFalse(PasswordPolicy::isValid('short'));
        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'short'));

        // Token remains usable since the reset never actually happened.
        $this->assertTrue($this->service->checkToken($id, $rawToken));
    }

    public function testResetPasswordFailsForExpiredToken(): void
    {
        $this->userRepo->create('expired2@test.com');
        [$id, $rawToken] = $this->createRawToken('expired2@test.com', expired: true);

        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1'));
    }

    /**
     * Issue #1257: checking a token and consuming it were two steps, so two
     * requests holding the same link could both pass the check and each set
     * a password. The loser of the claim changes nothing: no password, no
     * journal line, no passkey taken back.
     */
    public function testTheLoserOfTheClaimChangesNothing(): void
    {
        $account = $this->userRepo->create('race@test.com');
        $this->userRepo->updatePasswordHash($account->id, password_hash('Original-Pass-1', PASSWORD_DEFAULT));
        [$id, $rawToken] = $this->createRawToken('race@test.com');
        $connection = $this->createStub(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);
        $loser = new PasswordResetService(
            $connection,
            $this->encryption,
            $this->createStub(MailService::class),
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, new Environment(new ArrayLoader([]))),
            'https://example.com',
            'Test Unit',
            new class ($this->pdo) extends \Core\Security\PasswordResetRepository {
                public function markUsed(int $id): bool
                {
                    // The other request got there first, after our check.
                    parent::markUsed($id);

                    return false;
                }
            }
        );
        $loser->setJournalService(new JournalService(new JournalRepository($this->pdo)));

        $this->assertFalse($loser->resetPassword($id, $rawToken, 'Attacker-Choice-1'));

        $reloaded = $this->userRepo->findById($account->id);
        $this->assertTrue(password_verify('Original-Pass-1', (string) $reloaded?->passwordHash));
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'password_reset_completed'")
                ->fetchColumn()
        );
    }

    /**
     * Consuming the token and writing the password are one transaction: a
     * password that cannot be written must not leave the link spent, or the
     * holder is told to start again for nothing.
     */
    public function testAPasswordThatCannotBeWrittenLeavesTheTokenUsable(): void
    {
        $this->userRepo->create('atomic@test.com');
        [$id, $rawToken] = $this->createRawToken('atomic@test.com');
        $this->pdo->exec(
            "CREATE TRIGGER refuse_password BEFORE UPDATE ON user_accounts
             BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            $this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1');
            $this->fail('The refused write must surface.');
        } catch (\PDOException) {
            // expected
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->pdo->exec('DROP TRIGGER refuse_password');
        $this->assertTrue($this->service->checkToken($id, $rawToken));
        $this->assertTrue($this->service->resetPassword($id, $rawToken, 'MyNewSecureP@ss1'));
    }

    public function testResetPasswordRefusesAnAlreadyConsumedTokenWithoutTouchingThePassword(): void
    {
        $account = $this->userRepo->create('consumed@test.com');
        $this->userRepo->updatePasswordHash($account->id, password_hash('Original-Pass-1', PASSWORD_DEFAULT));
        [$id, $rawToken] = $this->createRawToken('consumed@test.com');
        $this->pdo->prepare('UPDATE password_reset_tokens SET used = 1 WHERE id = ?')->execute([$id]);

        $this->assertFalse($this->service->resetPassword($id, $rawToken, 'Attacker-Choice-1'));
        $this->assertTrue(password_verify(
            'Original-Pass-1',
            (string) $this->userRepo->findById($account->id)?->passwordHash
        ));
    }

    /**
     * @return array{0: int, 1: string} [token id, raw token]
     */
    private function createRawToken(string $email, bool $expired = false): array
    {
        $blindIndex = $this->encryption->blindIndex(strtolower($email), 'email');
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = $expired
            ? (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')
            : (new \DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (email_blind_index, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$blindIndex, $tokenHash, $expiresAt]);

        return [(int) $this->pdo->lastInsertId(), $rawToken];
    }

    /**
     * The token rides in the URL FRAGMENT, never the query string. A
     * fragment is not transmitted by the browser at all, which keeps the
     * token out of the web server's access log, any proxy in front of it,
     * and the Referer header the reset page would otherwise leak to
     * whatever it loads next.
     */
    public function testTheEmailedLinkCarriesTheTokenInTheFragmentNotTheQueryString(): void
    {
        $this->userRepo->create('reset@test.com');

        $this->service->requestReset('reset@test.com');

        $this->assertTrue($this->emailSent);
        $this->assertNotSame('', $this->capturedResetUrl);
        $this->assertStringNotContainsString('?token=', $this->capturedResetUrl);
        $this->assertStringNotContainsString('&token=', $this->capturedResetUrl);
        $this->assertMatchesRegularExpression('#/password-reset/\d+\#[0-9a-f]{64}$#', $this->capturedResetUrl);
    }

    /**
     * And the token in that fragment must still be the real, working one —
     * moving where it travels must not break what it does.
     */
    public function testTheTokenFromTheFragmentIsTheOneThatValidates(): void
    {
        $this->userRepo->create('reset2@test.com');
        $this->service->requestReset('reset2@test.com');

        [$path, $token] = explode('#', $this->capturedResetUrl, 2);
        $id = (int) substr($path, (int) strrpos($path, '/') + 1);

        $this->assertTrue($this->service->checkToken($id, $token));
        $this->assertFalse($this->service->checkToken($id, 'not-the-token'));
    }

    /**
     * Issue #1115: what the reset page may list is gated by the same token
     * check as the form itself — a dead link lists nothing — and with no
     * revoker wired the lists are simply empty.
     */
    public function testLiveAccessesForTokenNeedsAValidTokenAndIsEmptyWithoutARevoker(): void
    {
        $this->userRepo->create('accesses@test.com');
        $this->service->requestReset('accesses@test.com');
        [$id, $raw] = $this->tokenFromCapturedUrl();

        $this->assertNull($this->service->liveAccessesForToken($id, 'wrong'));
        $this->assertNull($this->service->liveAccessesForToken($id + 1000, $raw));
        $this->assertSame(['passkeys' => [], 'devices' => []], $this->service->liveAccessesForToken($id, $raw));
    }

    /**
     * The reset is what revokes: a passkey added before it (by an attacker
     * holding a stolen session) is gone after it, unless named as kept.
     */
    public function testResetPasswordRevokesEveryPasskeyExceptTheKeptOnes(): void
    {
        $account = $this->userRepo->create('revoke@test.com');
        $passkeys = new \Core\Security\WebAuthnCredentialRepository($this->pdo);
        $this->service->setAccessRevoker(new \Core\Security\AccountAccessRevoker(
            $passkeys,
            new \Core\Contact\Device\DeviceCredentialRepository($this->pdo),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo))
        ));
        $kept = $passkeys->create($account->id, random_bytes(16), 'pk', 'Le mien');
        $passkeys->create($account->id, random_bytes(16), 'pk', 'Celui de l\'attaquant');

        $this->service->requestReset('revoke@test.com');
        [$id, $raw] = $this->tokenFromCapturedUrl();

        $this->assertTrue($this->service->resetPassword($id, $raw, 'MyNewSecureP@ss1', [$kept], []));
        $this->assertSame(
            [$kept],
            array_map(static fn(array $r): int => (int) $r['id'], $passkeys->findByUserAccountId($account->id))
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function tokenFromCapturedUrl(): array
    {
        $this->assertSame(1, preg_match('#/password-reset/(\d+)\#(.+)$#', $this->capturedResetUrl, $m));

        return [(int) $m[1], rawurldecode($m[2])];
    }
}
