<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\Role;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Service\AuthorOptionsService;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupSessionContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * "Publier en tant que" — the one control in this module where the
 * MEMBERSHIP is what is being chosen rather than who is speaking, and
 * where naming therefore has to say both halves at once.
 *
 * @group database
 */
#[Group('database')]
class AuthorOptionsServiceTest extends TestCase
{
    private \PDO $pdo;
    private DiscussionGroup $group;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->group = new DiscussionGroup(
            1, 'Louveteaux', 1, null, null, '2026-01-01 10:00:00', 1, '2026-01-01 09:00:00'
        );
    }

    /**
     * @param int[] $allowed
     */
    private function service(array $allowed): AuthorOptionsService
    {
        $access = $this->createMock(GroupAccessService::class);
        $access->method('memberIdsAllowedToPostAs')->willReturn($allowed);

        $encryption = GroupsTestHelper::testEncryption();

        return new AuthorOptionsService(
            $access,
            new MemberService(
                new MemberYearRepository($this->pdo),
                $encryption,
                Connection::withPdo($this->pdo)
            ),
            GroupsTestHelper::identityService($this->pdo)
        );
    }

    private function context(): GroupSessionContext
    {
        return new GroupSessionContext(1, Role::IDENTIFIED, [], 1, true);
    }

    /**
     * The account in front, the ONE membership this option stands for in
     * the parentheses. The human name repeating down the list is the
     * point rather than noise: it is the signature each option would put
     * on the message.
     */
    public function testEachOptionIsTheAccountThenTheMembershipItSignsAs(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );

        $options = $this->service($seeded['member_ids'])->forGroup($this->group, $this->context());

        $this->assertSame([
            ['id' => $seeded['member_ids'][0], 'name' => 'Marie Dupont (Akéla)'],
            ['id' => $seeded['member_ids'][1], 'name' => 'Marie Dupont (Baloo)'],
        ], $options);
    }

    /**
     * Never every membership at once: that is the right shape for an
     * author line and the wrong one here, where it would print the same
     * string twice and leave the chooser with nothing to choose by.
     */
    public function testTwoOptionsOfTheSameParentAreNeverTheSameString(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']]
        );

        $names = array_column($this->service($seeded['member_ids'])->forGroup($this->group, $this->context()), 'name');

        $this->assertSame($names, array_unique($names));
    }

    /**
     * Fewer than two memberships is nothing to choose between, so the
     * selector is not rendered at all and this returns nothing to render.
     */
    public function testASingleMembershipIsNotAChoice(): void
    {
        $seeded = GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            'marie@example.test',
            'Marie',
            'Dupont',
            [['first_name' => 'Léa', 'totem' => 'Akéla']]
        );

        $this->assertSame([], $this->service($seeded['member_ids'])->forGroup($this->group, $this->context()));
    }

    /**
     * No named account behind the memberships leaves the totems standing
     * alone — which is all there is to say, and exactly what this control
     * showed before the account came first anywhere.
     */
    public function testMembershipsWithNoAccountKeepTheirOwnNames(): void
    {
        $ids = [];
        foreach ([['Léa', 'Akéla'], ['Tom', 'Baloo']] as $i => [$firstName, $totem]) {
            $memberId = GroupsTestHelper::createMember($this->pdo, 'NOACC' . $i);
            $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
                 VALUES (?, 1, ?, ?, ?, 1)'
            )->execute([
                $memberId,
                GroupsTestHelper::testEncryption()->encrypt($firstName, 'member_years.first_name'),
                GroupsTestHelper::testEncryption()->encrypt('Loup', 'member_years.last_name'),
                GroupsTestHelper::testEncryption()->encrypt($totem, 'member_years.totem'),
            ]);
            $ids[] = $memberId;
        }

        $names = array_column($this->service($ids)->forGroup($this->group, $this->context()), 'name');

        $this->assertSame(['Akéla', 'Baloo'], $names);
    }
}
