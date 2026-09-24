<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Support;

use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Support\PollVoterOptions;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * What a member-scoped poll's "Vous répondez pour" picker offers.
 *
 * Who is offered is Service\GroupAccessService's answer and is tested
 * there, so it is the one collaborator stubbed here. What each option is
 * CALLED is this class's own rule, and it runs against real
 * user_accounts/member_years rows through Service\MemberIdentityService —
 * the naming defect this picker had (#358) was entirely in which name it
 * settled for when the account lookup came back empty, which a stubbed
 * identity service would have hidden.
 *
 * @group database
 */
#[TestGroup('database')]
#[\PHPUnit\Framework\Attributes\Group('database')]
class PollVoterOptionsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
    }

    /**
     * Issue #358 — an animé with no login of their own is named by their
     * own totem or first name, never by their row number.
     *
     * This is the ORDINARY case in a section group, not an edge one: an
     * animé's only login is usually a parent's, and here the parent's
     * account is linked to neither child, so accountLabelForMembers()
     * answers '' for both. The picker used to fall straight through to
     * "Membre #40", offering a choice between two numbers.
     */
    public function testAMembershipWithNoAccountIsOfferedUnderItsOwnName(): void
    {
        [$lea, $tom] = $this->seedTwoMembersWithoutAccounts();

        $options = $this->optionsFor([$lea, $tom]);

        // The totem where there is one, the first name otherwise —
        // the display-name convention the rest of the module names
        // people by, so a parent meets the same word here and in
        // "qui a réagi".
        $this->assertSame(['Akéla', 'Tom'], array_column($options, 'name'));
        $this->assertSame([$lea, $tom], array_column($options, 'id'));
    }

    /**
     * An account behind the membership still wins: "Marie Dupont
     * (Akéla)" says who is actually answering, which is the whole point
     * of a picker that lets one human answer for another.
     */
    public function testAMembershipWithAnAccountKeepsItsAccountFirstLabel(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );

        $options = $this->optionsFor($seeded['member_ids']);

        $this->assertSame(['Marie Dupont (Akéla)', 'Marie Dupont (Baloo)'], array_column($options, 'name'));
    }

    /**
     * The bare id survives as the LAST resort, and only there: a
     * membership whose scout year holds no row for it has no name left
     * to show, and an option with no label at all is unpickable.
     */
    public function testAMembershipWithNoNameAtAllKeepsItsIdRatherThanAnEmptyOption(): void
    {
        GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $ghost = GroupsTestHelper::createMember($this->pdo, 'GHOST');
        [$lea] = $this->seedTwoMembersWithoutAccounts();

        $options = $this->optionsFor([$lea, $ghost]);

        $this->assertSame(['Akéla', 'Membre #' . $ghost], array_column($options, 'name'));
    }

    /**
     * One member is not a choice, and a dialog asking a question with one
     * answer is a click for nothing — unchanged by the naming fix.
     */
    public function testNothingIsOfferedWhenThereIsNothingToChooseBetween(): void
    {
        [$lea] = $this->seedTwoMembersWithoutAccounts();

        $this->assertSame([], $this->optionsFor([$lea]));
        $this->assertSame([], $this->optionsFor([]));
    }

    /**
     * Two members of the same section, each with their own member_years
     * row and NO user account linked to it: Léa carries a totem, Tom does
     * not.
     *
     * @return int[] the two member ids, Léa first
     */
    private function seedTwoMembersWithoutAccounts(): array
    {
        GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
        $encryption = GroupsTestHelper::testEncryption();

        $ids = [];
        foreach ([['Léa', 'Akéla'], ['Tom', null]] as [$firstName, $totem]) {
            $memberId = GroupsTestHelper::createMember($this->pdo, 'NO-ACCOUNT-' . uniqid());
            $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
                 VALUES (?, 1, ?, ?, ?, 1)'
            )->execute([
                $memberId,
                $encryption->encrypt($firstName, 'member_years.first_name'),
                $encryption->encrypt('Sansconnexion', 'member_years.last_name'),
                $totem !== null ? $encryption->encrypt($totem, 'member_years.totem') : null,
            ]);
            $ids[] = $memberId;
        }

        return $ids;
    }

    /**
     * The picker's answer for a section group whose votable members are
     * exactly these, in this order.
     *
     * @param int[] $memberIds
     * @return array<int, array{id: int, name: string, in_group: bool}>
     */
    private function optionsFor(array $memberIds): array
    {
        $access = $this->createStub(GroupAccessService::class);
        $access->method('memberIdsAllowedToVoteAsBySide')
            ->willReturn(['in_group' => $memberIds, 'elsewhere' => []]);

        $group = new DiscussionGroup(1, 'Louveteaux', 1, 7, null, '2026-01-01 00:00:00', null, '2026-01-01 00:00:00');
        $context = new GroupSessionContext(null, Role::PUBLIC, $memberIds, 1, true);

        return PollVoterOptions::forGroup($access, GroupsTestHelper::identityService($this->pdo), $group, $context);
    }
}
