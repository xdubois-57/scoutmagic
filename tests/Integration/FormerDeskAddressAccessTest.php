<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Integration;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\ScoutYear\AuthorizationYears;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\SessionGeneration;
use Core\Security\SessionRevalidator;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;

/**
 * Changing a member's address in Desk is how a unit cuts somebody off from
 * that member's record — a separation, a mistyped address belonging to a
 * stranger, a recycled mailbox. Issue #1100: once the member had received a
 * single mailing, it cut off nothing.
 *
 * Resolving a mailing's recipients writes a 'desk' status row for the
 * member's Desk address (MemberEmailRepository::findOrCreateDeskOverride()),
 * and that row outlived the change of address. Every login and access
 * lookup then read it as a confirmed secondary address: the FORMER address
 * still logged in, still reached the member and its health record, and
 * inherited its role — a chief's, once the child became an animateur — with
 * nothing on any page to show it.
 *
 * Walked here through the real classes, in the order it happens: mailing,
 * change of address in Desk, then every door the former address used to
 * keep open. The change of address is written the way the import writes it
 * (member_years is updated in place); the import's own cleanup of the row
 * is DeskImportServiceTest's and MemberEmailRepositoryTest's, and the
 * former address must already be shut out without it — which is what the
 * rows every installation holds today rely on until it runs.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FormerDeskAddressAccessTest extends TestCase
{
    private const FORMER = 'former@test.com';
    private const CURRENT = 'current@test.com';
    private const ALIAS = 'alias@test.com';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $memberEmails;
    private MemberService $memberService;
    private RoleResolver $roleResolver;
    private UserAccountRepository $userRepo;
    private SessionGeneration $sessionGeneration;
    private int $scoutYearId;
    private int $memberId;
    private int $memberYearId;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        [$label, $yearStart, $yearEnd] = \Tests\DatabaseTestHelper::scoutYear();
        $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)')
            ->execute([$label, $yearStart, $yearEnd]);
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $connection = Connection::withPdo($this->pdo);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $this->memberEmails = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->userRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $this->roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo, $this->memberEmails);
        $this->memberService = new MemberService(
            $memberYearRepo,
            new MemberProfileRepository($connection, $this->encryption),
            null,
            $this->memberEmails
        );
        $this->sessionGeneration = \Tests\SessionGenerationFixture::forPdo($this->pdo);

        // The member, imported from Desk with the address the unit will
        // later replace — which, like every Desk address, has its account.
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['CHILD']);
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Enfant', 'member_years.first_name'),
            $this->encryption->encrypt('Test', 'member_years.last_name'),
            $this->encryption->encrypt(self::FORMER, 'member_years.email'),
            $this->encryption->blindIndex(self::FORMER, 'email'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
        $this->userRepo->create(self::FORMER);

        // A mailing reaches the member: resolving its recipients writes the
        // 'desk' row for the Desk address (MemberEmailService::
        // sendableRowsForMassMail()).
        $this->memberEmails->findOrCreateDeskOverride($this->memberId, self::FORMER);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheFormerAddressCanNoLongerLogIn(): void
    {
        $this->changeDeskAddressTo(self::CURRENT);

        $this->assertFalse($this->roleResolver->isEmailAuthorizedToLoginAcrossYears(self::FORMER, $this->years()));
    }

    public function testTheFormerAddressNoLongerReachesTheMember(): void
    {
        $this->changeDeskAddressTo(self::CURRENT);

        $this->assertSame([], $this->memberService->getLinkedMembers(self::FORMER, $this->scoutYearId));
        $this->assertFalse($this->memberService->canAccess(self::FORMER, $this->memberYearId, 'identified'));
        $this->assertFalse($this->memberService->isLinkedToMember(self::FORMER, $this->memberId, $this->scoutYearId));
    }

    /**
     * The sharpest part of it: once the child became an animateur, the
     * former address of a parent resolved `chief` for the whole unit.
     */
    public function testTheFormerAddressNoLongerInheritsTheMembersRole(): void
    {
        $this->giveTheMemberAFunction('chief');
        $this->assertSame('chief', $this->roleResolver->resolveAcrossYears(self::FORMER, $this->years()));

        $this->changeDeskAddressTo(self::CURRENT);

        $this->assertSame('identified', $this->roleResolver->resolveAcrossYears(self::FORMER, $this->years()));
        $this->assertSame('chief', $this->roleResolver->resolveAcrossYears(self::CURRENT, $this->years()));
    }

    /**
     * A session the former address opened before the change ends at its
     * next request, through the gate SessionRevalidator replays.
     */
    public function testALiveSessionOfTheFormerAddressIsCutAtItsNextRequest(): void
    {
        $account = $this->userRepo->findByEmail(self::FORMER);
        $this->assertNotNull($account);
        $this->startTestSession();
        AuthSession::login($account->id, self::FORMER, 'identified', $this->sessionGeneration->current());
        $revalidator = new SessionRevalidator($this->userRepo, $this->roleResolver, $this->sessionGeneration);
        $this->assertTrue($revalidator->revalidate(fn(): AuthorizationYears => $this->years()));

        $this->changeDeskAddressTo(self::CURRENT);

        $this->assertFalse($revalidator->revalidate(fn(): AuthorizationYears => $this->years()));
        $this->assertFalse(AuthSession::isAuthenticated());
    }

    /**
     * What the unit wanted keeps working: the new Desk address, and a
     * secondary address the member confirmed themselves.
     */
    public function testTheNewDeskAddressAndAConfirmedSecondaryAddressStillWork(): void
    {
        $this->memberEmails->create(
            $this->memberId,
            self::ALIAS,
            MemberEmail::SOURCE_MANUAL,
            MemberEmail::STATUS_VALID,
            null,
            null
        );

        $this->changeDeskAddressTo(self::CURRENT);

        foreach ([self::CURRENT, self::ALIAS] as $address) {
            $this->assertTrue(
                $this->roleResolver->isEmailAuthorizedToLoginAcrossYears($address, $this->years()),
                "{$address} must still log in"
            );
            $this->assertTrue(
                $this->memberService->canAccess($address, $this->memberYearId, 'identified'),
                "{$address} must still reach the member"
            );
        }
    }

    /**
     * Before any change, the 'desk' row is not what lets the Desk address
     * in — member_years is — and an explicit 'inactive' on it still shuts
     * that address out, as it did.
     */
    public function testTheCurrentDeskAddressIsStillGovernedByItsStatusRow(): void
    {
        $this->assertTrue($this->roleResolver->isEmailAuthorizedToLoginAcrossYears(self::FORMER, $this->years()));

        $this->pdo->exec("UPDATE member_emails SET status = 'inactive' WHERE source = 'desk'");

        $this->assertFalse($this->roleResolver->isEmailAuthorizedToLoginAcrossYears(self::FORMER, $this->years()));
    }

    /** What a Desk import does to a member whose address changed. */
    private function changeDeskAddressTo(string $email): void
    {
        $this->pdo->prepare('UPDATE member_years SET email_encrypted = ?, email_blind_index = ? WHERE id = ?')
            ->execute([
                $this->encryption->encrypt($email, 'member_years.email'),
                $this->encryption->blindIndex($email, 'email'),
                $this->memberYearId,
            ]);
    }

    private function giveTheMemberAFunction(string $role): void
    {
        $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, 1)')
            ->execute(['ANIM', 'Animateur', $role]);
        $functionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        )->execute([$this->memberYearId, $functionId]);
    }

    /** No cookie and no cache headers: this is a CLI run (same as SecondaryEmailLoginIdentityTest). */
    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }

    private function years(): AuthorizationYears
    {
        return new AuthorizationYears($this->scoutYearId, [$this->scoutYearId]);
    }
}
