<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Service\MemberIdentityService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * How this module names a person, everywhere it names one: the account —
 * the human — then the memberships that account carries.
 *
 * Resolved over real user_accounts and member_years rows through the blind
 * index that already joins them for login, so these tests exercise the
 * actual join and not a stub's opinion of it.
 *
 * @group database
 */
#[Group('database')]
class MemberIdentityServiceTest extends TestCase
{
    private \PDO $pdo;
    private MemberIdentityService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->service = GroupsTestHelper::identityService($this->pdo);
    }

    public function testAnAccountIsNamedWithItsOwnMembershipInParentheses(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Marie', 'totem' => 'Akéla']]
        );

        $identity = $this->service->forAccounts([$seeded['account_id']], 1)[$seeded['account_id']];

        $this->assertSame('Marie Dupont', $identity['account_name']);
        $this->assertSame(['Akéla'], $identity['member_names']);
        $this->assertSame('Marie Dupont (Akéla)', MemberIdentityService::label($identity));
    }

    /**
     * ALL of them: a parent linked to three children is one human with
     * three memberships, and which one a given message was signed as is
     * stored rather than displayed.
     */
    public function testAnAccountListsEveryMembershipItCarries(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'claire@example.test',
            'Claire',
            'Martin',
            [
                ['first_name' => 'Léa', 'totem' => 'Akéla'],
                ['first_name' => 'Tom', 'totem' => 'Baloo'],
                ['first_name' => 'Zoé'],
            ]
        );

        $identity = $this->service->forAccounts([$seeded['account_id']], 1)[$seeded['account_id']];

        // Totem where there is one, first name otherwise — the project's
        // display-name convention, applied by MemberService and not
        // reimplemented here.
        $this->assertSame(['Akéla', 'Baloo', 'Zoé'], $identity['member_names']);
        $this->assertSame('Claire Martin (Akéla, Baloo, Zoé)', MemberIdentityService::label($identity));
    }

    /**
     * The memberships are the ones of the year being asked about, which is
     * what keeps an archived group naming the people who were in it THAT
     * year rather than whoever holds the address now.
     */
    public function testMembershipsAreScopedToTheYearAskedAbout(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Marie', 'totem' => 'Akéla']],
            1
        );

        $otherYear = $this->service->forAccounts([$seeded['account_id']], 2)[$seeded['account_id']];

        $this->assertSame('Marie Dupont', $otherYear['account_name']);
        $this->assertSame([], $otherYear['member_names'], 'no membership that year, so nothing in the parentheses');
        $this->assertSame('Marie Dupont', MemberIdentityService::label($otherYear));
    }

    /**
     * An account whose owner never filled in "Mon compte" still belongs to
     * somebody: its memberships become the name rather than an aside
     * sitting behind an empty one.
     */
    public function testAnAccountWithNoNameFallsBackToItsMemberships(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'anonyme@example.test',
            '',
            '',
            [['first_name' => 'Chil', 'totem' => 'Chil']]
        );

        $identity = $this->service->forAccounts([$seeded['account_id']], 1)[$seeded['account_id']];

        $this->assertSame('Chil', $identity['account_name']);
        $this->assertSame([], $identity['member_names']);
        $this->assertSame('Chil', MemberIdentityService::label($identity));
    }

    public function testAMemberIsNamedThroughTheAccountBehindThem(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );

        $identities = $this->service->forMembers($seeded['member_ids'], 1);

        // Both memberships resolve to the SAME human, which is the whole
        // point: "qui a réagi" must not read as two people.
        foreach ($seeded['member_ids'] as $memberId) {
            $this->assertSame('Marie Dupont (Akéla, Baloo)', MemberIdentityService::label($identities[$memberId]));
        }
    }

    /**
     * Most animés have no account at all. There is no human name to put in
     * front of them, and inventing one would be worse than their own.
     */
    public function testAMemberWithNoAccountIsNamedByTheirOwnDisplayName(): void
    {
        $memberId = GroupsTestHelper::createMember($this->pdo, 'NO-ACCOUNT');
        GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, 1, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.first_name'),
            GroupsTestHelper::testEncryption()->encrypt('Loup', 'member_years.last_name'),
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.totem'),
        ]);

        $identity = $this->service->forMembers([$memberId], 1)[$memberId];

        $this->assertSame('Chil', $identity['account_name']);
        $this->assertSame([], $identity['member_names']);
    }

    public function testAMemberThatDoesNotExistAtAllResolvesToNothing(): void
    {
        $identity = $this->service->forMembers([999], 1)[999];

        $this->assertSame('', MemberIdentityService::label($identity));
    }

    public function testEmptyInputAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->service->forAccounts([], 1));
        $this->assertSame([], $this->service->forMembers([], 1));
        // A zero or negative id is not an id — filtered out rather than
        // queried for.
        $this->assertSame([], $this->service->forAccounts([0, -1], 1));
    }

    // ---- accountLabelForMembers: the account, narrowed to ONE membership ----

    /**
     * Where the membership is what is being picked rather than who is
     * speaking — "Publier en tant que", the invite-candidate search — the
     * parentheses hold that one membership, so two rows of the same
     * parent can be told apart.
     */
    public function testEachMembershipIsLabelledWithItsOwnParenthesisOnly(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );
        [$lea, $tom] = $seeded['member_ids'];

        $labels = $this->service->accountLabelForMembers($seeded['member_ids'], 1);

        $this->assertSame('Marie Dupont (Akéla)', $labels[$lea]);
        $this->assertSame('Marie Dupont (Baloo)', $labels[$tom]);
        // And NOT what forMembers() gives, which is right for an author
        // and useless for a chooser: both would read the same.
        $this->assertNotSame($labels[$lea], $labels[$tom]);
    }

    /**
     * Nothing rather than a guess: there is no human name to put in front
     * of them, and each caller's own fallback (the totem for the author
     * selector, the full name for the invite search) beats a shared one.
     */
    public function testAMembershipWithNoAccountIsLabelledWithNothing(): void
    {
        $memberId = GroupsTestHelper::createMember($this->pdo, 'NO-ACCOUNT');
        GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, 1, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.first_name'),
            GroupsTestHelper::testEncryption()->encrypt('Loup', 'member_years.last_name'),
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.totem'),
        ]);

        $this->assertSame('', $this->service->accountLabelForMembers([$memberId], 1)[$memberId]);
    }

    /**
     * An account with no name of its own is not a name either — and must
     * NOT borrow forAccounts()'s fallback here, which would turn the
     * memberships into the name and render "Akéla, Baloo (Akéla)".
     */
    public function testAnAccountWithNoNameIsLabelledWithNothing(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'anonyme@example.test',
            '',
            '',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );

        foreach ($seeded['member_ids'] as $memberId) {
            $this->assertSame('', $this->service->accountLabelForMembers([$memberId], 1)[$memberId]);
        }
    }

    /**
     * A chef whose account carries the same name as their own membership
     * would otherwise read "Marie Dupont (Marie Dupont)".
     */
    public function testAnAccountNamedLikeItsMembershipDoesNotSayItTwice(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Marie Dupont']]
        );
        $memberId = $seeded['member_ids'][0];

        $this->assertSame(
            'Marie Dupont',
            $this->service->accountLabelForMembers([$memberId], 1)[$memberId]
        );
    }

    public function testAccountLabelsAskTheDatabaseNothingForAnEmptyList(): void
    {
        $this->assertSame([], $this->service->accountLabelForMembers([], 1));
        $this->assertSame([], $this->service->accountLabelForMembers([0, -3], 1));
    }

    /**
     * The memo is what keeps a feed page of twenty messages by the same
     * author from resolving that author twenty times — and it must not
     * leak across scout years, since the memberships differ per year.
     */
    public function testTheSameAccountIsResolvedOncePerYearAndThenRemembered(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Marie', 'totem' => 'Akéla']]
        );

        $first = $this->service->forAccounts([$seeded['account_id']], 1)[$seeded['account_id']];

        // The row disappears; a memoised answer must still come back.
        $this->pdo->exec('DELETE FROM member_years');
        $second = $this->service->forAccounts([$seeded['account_id']], 1)[$seeded['account_id']];
        $this->assertSame($first, $second);

        // Another year is another question, asked afresh — and now finds
        // nothing, which proves the memo is keyed on the year too.
        $otherYear = $this->service->forAccounts([$seeded['account_id']], 2)[$seeded['account_id']];
        $this->assertSame([], $otherYear['member_names']);
    }
}
