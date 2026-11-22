<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionAccountRepository;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1348, lot A: « which accounts belong to this section this year? » —
 * the question a notification about a SECTION object (an activity, an album)
 * has to answer, and that `findIdsForSectionAudience()` answered wrongly in
 * two ways: it added every chef, admin and super-administrator to every
 * section, and it only followed the Desk address, so a parent reached through
 * a confirmed secondary address was never told.
 *
 * Real tables and real repositories: what is under test is the link itself —
 * a member who holds a function in the section, reached through the account's
 * login address.
 */
#[Group('database')]
final class SectionAccountRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private UserAccountRepository $accounts;
    private MemberEmailRepository $secondary;
    private SectionAccountRepository $resolver;
    private int $year;
    private int $otherYear;
    private int $goelands;
    private int $pionniers;
    private int $seeonee;
    private int $animeFunction;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $this->secondary = new MemberEmailRepository($this->pdo, $this->encryption);
        $this->resolver = new SectionAccountRepository($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('B1', 'Branche', 10)");
        $branch = (int) $this->pdo->lastInsertId();
        foreach (['goelands' => 'GOE', 'pionniers' => 'PIO', 'seeonee' => 'SEE'] as $property => $code) {
            $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
            $stmt->execute([$code, $branch, ucfirst($property)]);
            $this->{$property} = (int) $this->pdo->lastInsertId();
        }
        foreach (['2025-2026' => ['2025-09-01', '2026-08-31', 1], '2024-2025' => ['2024-09-01', '2025-08-31', 0]] as $label => [$start, $end, $current]) {
            $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, ?)');
            $stmt->execute([$label, $start, $end, $current]);
            if ($current === 1) {
                $this->year = (int) $this->pdo->lastInsertId();
            } else {
                $this->otherYear = (int) $this->pdo->lastInsertId();
            }
        }
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANI', 'Animé', 'identified', 1)");
        $this->animeFunction = (int) $this->pdo->lastInsertId();
    }

    /**
     * A member of $section in $year, whose Desk address is $deskEmail.
     *
     * @return array{member: int, member_year: int}
     */
    private function member(string $deskEmail, int $section, ?int $year = null, bool $active = true, ?int $function = null): array
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['D' . uniqid()]);
        $member = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             email_encrypted, email_blind_index, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $member, $year ?? $this->year,
            $this->encryption->encrypt('Prénom', 'member_years.first_name'),
            $this->encryption->encrypt('Nom', 'member_years.last_name'),
            $this->encryption->encrypt($deskEmail, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($deskEmail), 'email'),
            $active ? 1 : 0,
        ]);
        $memberYear = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)')
            ->execute([$memberYear, $function ?? $this->animeFunction, $section]);

        return ['member' => $member, 'member_year' => $memberYear];
    }

    private function account(string $email): int
    {
        return $this->accounts->create($email)->id;
    }

    /** @return list<int> */
    private function linked(array $sections, ?array $years = null): array
    {
        $ids = $this->resolver->accountIdsLinkedToSections($sections, $years ?? [$this->year]);
        sort($ids);

        return $ids;
    }

    public function testAnAccountIsLinkedThroughTheDeskAddressOfAMemberOfTheSection(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);

        $this->assertSame([$parent], $this->linked([$this->goelands]));
        $this->assertSame([], $this->linked([$this->seeonee]), 'another section is another audience');
    }

    /** Several children, several functions: one account, once. */
    public function testAnAccountLinkedTwiceToTheSameSectionIsReturnedOnce(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $this->member('parent@test.be', $this->goelands);

        $this->assertSame([$parent], $this->linked([$this->goelands]));
    }

    /** The reported case: an account tied to two sections through two children. */
    public function testAnAccountIsLinkedToEachSectionOfItsChildrenAndOnlyThose(): void
    {
        $parent = $this->account('admin-parent@test.be');
        $this->member('admin-parent@test.be', $this->goelands);
        $this->member('admin-parent@test.be', $this->pionniers);

        $this->assertSame([$parent], $this->linked([$this->goelands]));
        $this->assertSame([$parent], $this->linked([$this->pionniers]));
        $this->assertSame([$parent], $this->linked([$this->goelands, $this->pionniers]));
        $this->assertSame([], $this->linked([$this->seeonee]));
    }

    /**
     * The defect: a role alone subscribed an account to every section. The
     * account here is a chef d'unité and a super-administrator, linked to
     * none of the sections it is asked about.
     */
    public function testARoleAloneLinksNobodyToASection(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Chef', 'admin', 1)");
        $chiefFunction = (int) $this->pdo->lastInsertId();
        $chief = $this->account('chef@test.be');
        $this->member('chef@test.be', $this->goelands, function: $chiefFunction);
        $super = $this->account('super@test.be');
        $this->pdo->prepare('UPDATE user_accounts SET is_super_admin = 1 WHERE id = ?')->execute([$super]);

        $this->assertSame([$chief], $this->linked([$this->goelands]), 'the chef IS linked to their own section');
        $this->assertSame([], $this->linked([$this->seeonee]), 'but a chef is not subscribed to the others');
        $this->assertNotContains($super, $this->linked([$this->goelands, $this->seeonee]));
    }

    /** The second defect: the Desk address was the only way in. */
    public function testAValidatedSecondaryAddressReachesTheSameSection(): void
    {
        $child = $this->member('desk-family@test.be', $this->goelands);
        $teen = $this->account('teen@test.be');
        $this->secondary->create($child['member'], 'teen@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);

        $this->assertSame([$teen], $this->linked([$this->goelands]));
    }

    public function testAPendingOrInactiveSecondaryAddressCreatesNoLink(): void
    {
        $child = $this->member('desk-family@test.be', $this->goelands);
        $pending = $this->account('pending@test.be');
        $inactive = $this->account('inactive@test.be');
        $this->secondary->create($child['member'], 'pending@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_PENDING, null, null);
        $this->secondary->create($child['member'], 'inactive@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_INACTIVE, null, null);

        $linked = $this->linked([$this->goelands]);

        $this->assertNotContains($pending, $linked);
        $this->assertNotContains($inactive, $linked);
    }

    /** A Desk address and a secondary one for the same account: still one entry. */
    public function testTheDeskAddressAndASecondaryOneOfTheSameAccountCountOnce(): void
    {
        $child = $this->member('both@test.be', $this->goelands);
        $both = $this->account('both@test.be');
        $this->secondary->create($child['member'], 'both@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);

        $this->assertSame([$both], $this->linked([$this->goelands]));
    }

    public function testOnlyTheYearsAskedForCount(): void
    {
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands, $this->otherYear);

        $this->assertSame([], $this->linked([$this->goelands]));
        $this->assertSame([$parent], $this->linked([$this->goelands], [$this->otherYear]));
        $this->assertSame([$parent], $this->linked([$this->goelands], [$this->year, $this->otherYear]));
    }

    public function testAnInactiveMembershipLinksNobody(): void
    {
        $this->account('left@test.be');
        $this->member('left@test.be', $this->goelands, active: false);

        $this->assertSame([], $this->linked([$this->goelands]));
    }

    public function testNothingAskedNothingAnswered(): void
    {
        $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);

        $this->assertSame([], $this->resolver->accountIdsLinkedToSections([], [$this->year]));
        $this->assertSame([], $this->resolver->accountIdsLinkedToSections([$this->goelands], []));
    }

    /** A member with no account at all — the common case of a young member — is no one. */
    public function testAMemberWithNoAccountIsNoOne(): void
    {
        $this->member('nobody@test.be', $this->goelands);

        $this->assertSame([], $this->linked([$this->goelands]));
    }

    /**
     * The cadres, reached the way login reaches them: through the Desk address
     * OR a confirmed secondary address (review of #1379). The role filter used
     * by the notifications must follow the same two roads as the link, or a
     * chef who signs in by a secondary address is « linked » yet never
     * « allowed ».
     */
    public function testTheCadresIncludeThoseReachedByAConfirmedSecondaryAddress(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CHE', 'Chef', 'chief', 1)");
        $chiefFunction = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Chef d unité', 'admin', 1)");
        $adminFunction = (int) $this->pdo->lastInsertId();
        $desk = $this->account('desk-chief@test.be');
        $this->member('desk-chief@test.be', $this->goelands, function: $chiefFunction);
        $viaSecondary = $this->account('secondary-chief@test.be');
        $chiefMember = $this->member('family-chief@test.be', $this->goelands, function: $chiefFunction);
        $this->secondary->create($chiefMember['member'], 'secondary-chief@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);
        $pending = $this->account('pending-chief@test.be');
        $this->secondary->create($chiefMember['member'], 'pending-chief@test.be', MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_PENDING, null, null);
        $parent = $this->account('parent@test.be');
        $this->member('parent@test.be', $this->goelands);
        $admin = $this->account('admin@test.be');
        $this->member('admin@test.be', $this->pionniers, function: $adminFunction);
        $super = $this->account('super@test.be');
        $this->pdo->prepare('UPDATE user_accounts SET is_super_admin = 1 WHERE id = ?')->execute([$super]);

        $cadres = $this->resolver->cadreAccountIds([$this->year]);
        sort($cadres);
        $this->assertSame([$desk, $viaSecondary, $admin, $super], $cadres);
        $this->assertNotContains($pending, $cadres);
        $this->assertNotContains($parent, $cadres);

        $admins = $this->resolver->cadreAccountIds([$this->year], ['admin']);
        sort($admins);
        $this->assertSame([$admin, $super], $admins);
    }
}
