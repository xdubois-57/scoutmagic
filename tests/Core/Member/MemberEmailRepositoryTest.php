<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberEmailRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberEmailRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new MemberEmailRepository($this->pdo, $this->encryption);
    }

    /**
     * The plaintext twin of findMemberIdsByValidBlindIndex(), for the caller
     * that needs the address itself: Core\Security\AuthService, giving a
     * confirmed secondary address its own account on first login.
     */
    public function testFindValidByBlindIndexReturnsTheDecryptedAddress(): void
    {
        $this->insert(1, 'alias@test.com', MemberEmail::STATUS_VALID);

        $rows = $this->repository->findValidByBlindIndex($this->blindIndex('alias@test.com'));

        $this->assertCount(1, $rows);
        $this->assertSame('alias@test.com', $rows[0]->email);
        $this->assertSame(1, $rows[0]->memberId);
    }

    /**
     * The same address confirmed on several members (a parent's address on
     * each of their children) is several rows, all carrying that one
     * address — ordered by id, so the caller's "first row wins" is stable.
     */
    public function testFindValidByBlindIndexReturnsEveryMemberHoldingThatAddress(): void
    {
        $this->insert(7, 'parent@test.com', MemberEmail::STATUS_VALID);
        $this->insert(3, 'parent@test.com', MemberEmail::STATUS_VALID);

        $rows = $this->repository->findValidByBlindIndex($this->blindIndex('parent@test.com'));

        $this->assertSame([7, 3], array_map(static fn(MemberEmail $row) => $row->memberId, $rows));
    }

    /**
     * A 'pending' or 'inactive' row grants nothing anywhere — not a login,
     * not a notification (same rule as Core\Security\RoleResolver).
     */
    public function testFindValidByBlindIndexIgnoresPendingAndInactiveRows(): void
    {
        $this->insert(1, 'unconfirmed@test.com', MemberEmail::STATUS_PENDING);
        $this->insert(2, 'unsubscribed@test.com', MemberEmail::STATUS_INACTIVE);

        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('unconfirmed@test.com')));
        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('unsubscribed@test.com')));
    }

    public function testFindValidByBlindIndexReturnsNothingForAnUnknownAddress(): void
    {
        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('nobody@test.com')));
    }

    /**
     * The login keys of a member's confirmed secondary addresses, derived by
     * the repository that owns the purpose string (issue #629) — and derived
     * from the address as findByMemberAndEmail() normalizes it, so a stray
     * capital or space in what was stored cannot give a key no account has.
     * Pending, inactive and Desk-override rows are not addresses the member
     * reads mail at through this path.
     */
    public function testFindValidBlindIndexesByMemberGivesTheLoginKeyOfEachConfirmedAddress(): void
    {
        $this->insert(4, ' Alias@Test.com ', MemberEmail::STATUS_VALID);
        $this->insert(4, 'second@test.com', MemberEmail::STATUS_VALID);
        $this->insert(4, 'pending@test.com', MemberEmail::STATUS_PENDING);
        $this->insert(4, 'gone@test.com', MemberEmail::STATUS_INACTIVE);
        $this->insert(5, 'someone-else@test.com', MemberEmail::STATUS_VALID);
        $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (4, ?, ?, 'desk', 'valid')"
        )->execute([
            $this->encryption->encrypt('desk@test.com', 'member_emails.email'),
            $this->blindIndex('desk@test.com'),
        ]);

        $this->assertEqualsCanonicalizing(
            [$this->blindIndex('alias@test.com'), $this->blindIndex('second@test.com')],
            $this->repository->findValidBlindIndexesByMember(4)
        );
    }

    /**
     * Issue #1100. A 'desk' row is the status override a mailing writes for
     * a member's Desk address, and it outlives a change of that address in
     * Desk: counting it made the FORMER address a login, an access and a
     * role of its own. Neither login lookup reads one, whatever its status.
     */
    public function testTheLoginLookupsIgnoreADeskRow(): void
    {
        $this->insertDesk(4, 'former@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame([], $this->repository->findMemberIdsByValidBlindIndex($this->blindIndex('former@test.com')));
        $this->assertSame([], $this->repository->findValidByBlindIndex($this->blindIndex('former@test.com')));
    }

    /**
     * ...while a confirmed secondary address of the same member, and the
     * same address confirmed by hand on another member, still resolve.
     */
    public function testTheLoginLookupsStillResolveAConfirmedManualAddress(): void
    {
        $this->insertDesk(4, 'shared@test.com', MemberEmail::STATUS_VALID);
        $this->insert(5, 'shared@test.com', MemberEmail::STATUS_VALID);
        $this->insert(4, 'alias@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame([5], $this->repository->findMemberIdsByValidBlindIndex($this->blindIndex('shared@test.com')));
        $this->assertSame(
            [5],
            array_map(
                static fn(MemberEmail $row) => $row->memberId,
                $this->repository->findValidByBlindIndex($this->blindIndex('shared@test.com'))
            )
        );
        $this->assertSame([4], $this->repository->findMemberIdsByValidBlindIndex($this->blindIndex('alias@test.com')));
    }

    /**
     * The cleanup of issue #1100: a 'desk' row whose address is no longer
     * the member's Desk address in any year still open goes; the row of the
     * address Desk gives the member today stays, with its status.
     */
    public function testDeleteStaleDeskOverridesDeletesTheRowOfAFormerDeskAddressOnly(): void
    {
        $yearId = $this->scoutYear('2026-08-01', '2027-07-31');
        $this->memberYear(4, $yearId, 'current@test.com');
        $this->insertDesk(4, 'former@test.com', MemberEmail::STATUS_VALID);
        $this->insertDesk(4, 'current@test.com', MemberEmail::STATUS_INACTIVE);
        $this->insert(4, 'alias@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame(1, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-11-12'), [$yearId]));

        $this->assertNull($this->repository->findByMemberAndEmail(4, 'former@test.com'));
        $this->assertSame(
            MemberEmail::STATUS_INACTIVE,
            $this->repository->findByMemberAndEmail(4, 'current@test.com')?->status
        );
        $this->assertNotNull($this->repository->findByMemberAndEmail(4, 'alias@test.com'));
    }

    /**
     * The address of another member does not keep a row alive: the match is
     * on the row's own member.
     */
    public function testDeleteStaleDeskOverridesMatchesTheRowsOwnMember(): void
    {
        $yearId = $this->scoutYear('2026-08-01', '2027-07-31');
        $this->memberYear(5, $yearId, 'parent@test.com');
        $this->insertDesk(4, 'parent@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame(1, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-11-12'), [$yearId]));
        $this->assertNull($this->repository->findByMemberAndEmail(4, 'parent@test.com'));
    }

    /**
     * While two years are open, an address changed for next year is still
     * this year's: its row — an 'inactive' one above all — still applies to
     * it and stays. An ended year keeps nothing alive.
     */
    public function testDeleteStaleDeskOverridesKeepsTheAddressOfEveryYearNotYetEnded(): void
    {
        $ended = $this->scoutYear('2025-08-01', '2026-07-31');
        $current = $this->scoutYear('2026-08-01', '2027-07-31');
        $next = $this->scoutYear('2027-08-01', '2028-07-31');
        $this->memberYear(4, $ended, 'oldest@test.com');
        $this->memberYear(4, $current, 'this-year@test.com');
        $this->memberYear(4, $next, 'next-year@test.com');
        $this->insertDesk(4, 'oldest@test.com', MemberEmail::STATUS_VALID);
        $this->insertDesk(4, 'this-year@test.com', MemberEmail::STATUS_INACTIVE);
        $this->insertDesk(4, 'next-year@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame(1, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2027-07-31'), []));

        $this->assertNull($this->repository->findByMemberAndEmail(4, 'oldest@test.com'));
        $this->assertNotNull($this->repository->findByMemberAndEmail(4, 'this-year@test.com'));
        $this->assertNotNull($this->repository->findByMemberAndEmail(4, 'next-year@test.com'));
    }

    /**
     * A site whose transition has not been run yet in September still
     * serves a public year whose end date has passed: login reads it
     * (AuthorizationYears), so its addresses keep their rows.
     */
    public function testDeleteStaleDeskOverridesKeepsTheAddressOfAKeptYearPastItsEndDate(): void
    {
        $public = $this->scoutYear('2025-09-01', '2026-08-31');
        $this->memberYear(4, $public, 'current@test.com');
        $this->insertDesk(4, 'current@test.com', MemberEmail::STATUS_INACTIVE);
        $this->insertDesk(4, 'former@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame(1, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-09-15'), [$public]));

        $this->assertNotNull($this->repository->findByMemberAndEmail(4, 'current@test.com'));
        $this->assertNull($this->repository->findByMemberAndEmail(4, 'former@test.com'));
    }

    /**
     * The case #1100 is mostly about: the address changed across a year
     * boundary, so last year's member_years still carries the former one.
     * Last year's row says is_current = 1, like every row ensureYear()
     * ever inserted — that flag is never cleared and must keep nothing.
     */
    public function testDeleteStaleDeskOverridesIgnoresTheIsCurrentFlagOfAPastYear(): void
    {
        $last = $this->scoutYear('2025-09-01', '2026-08-31');
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT is_current FROM scout_years WHERE id = {$last}")->fetchColumn(),
            'premise: every year row carries is_current = 1, as ScoutYearService::ensureYear() writes it'
        );
        $thisYear = $this->scoutYear('2026-09-01', '2027-08-31');
        $this->memberYear(4, $last, 'former@test.com');
        $this->memberYear(4, $thisYear, 'current@test.com');
        $this->insertDesk(4, 'former@test.com', MemberEmail::STATUS_VALID);

        $this->assertSame(1, $this->repository->deleteStaleDeskOverrides(new \DateTimeImmutable('2026-11-12'), [$thisYear]));
        $this->assertNull($this->repository->findByMemberAndEmail(4, 'former@test.com'));
    }

    private function insertDesk(int $memberId, string $email, string $status): void
    {
        $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'desk', ?)"
        )->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->blindIndex($email),
            $status,
        ]);
    }

    /** is_current = 1, as ScoutYearService::ensureYear() writes every row. */
    private function scoutYear(string $start, string $end): int
    {
        $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        )->execute([substr($start, 0, 4) . '-' . substr($end, 0, 4), $start, $end]);

        return (int) $this->pdo->lastInsertId();
    }

    private function memberYear(int $memberId, int $scoutYearId, string $email): void
    {
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->blindIndex($email),
        ]);
    }

    private function insert(int $memberId, string $email, string $status): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status)
             VALUES (?, ?, ?, 'manual', ?)"
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email, 'member_emails.email'),
            $this->blindIndex($email),
            $status,
        ]);
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(strtolower($email), 'email');
    }
}
