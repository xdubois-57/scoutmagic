<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Service\MentionService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * "@Marie Dupont" turned into the people it names — resolved from the
 * stored body against the group's own membership, never from anything a
 * client sent.
 *
 * @group database
 */
#[Group('database')]
class MentionServiceTest extends TestCase
{
    private DiscussionGroup $group;

    protected function setUp(): void
    {
        $this->group = new DiscussionGroup(
            1, 'Louveteaux', 7, 3, null, '2026-01-01 10:00:00', 1, '2026-01-01 09:00:00'
        );
    }

    /**
     * @param int[] $memberIds
     * @param array<int, string> $names
     */
    private function service(array $memberIds, array $names): MentionService
    {
        $resolver = $this->createMock(GroupRecipientResolver::class);
        $resolver->method('memberIdsFor')->willReturn($memberIds);

        $memberService = $this->createMock(MemberService::class);
        $memberService->method('findDisplayNamesByMemberIds')->willReturn($names);

        return new MentionService($resolver, $memberService);
    }

    public function testItFindsTheMemberNamedAfterAnAt(): void
    {
        $service = $this->service([4, 5], [4 => 'Akéla', 5 => 'Baloo']);

        $this->assertSame([4], $service->resolve($this->group, 'Merci @Akéla pour hier', 7));
    }

    /**
     * A name is only a mention when it carries the "@": talking ABOUT
     * somebody is not talking TO them.
     */
    public function testANameWithoutAnAtIsNotAMention(): void
    {
        $service = $this->service([4], [4 => 'Akéla']);

        $this->assertSame([], $service->resolve($this->group, 'Akéla a apporté le matériel', 7));
    }

    /**
     * The candidate list is the group's membership and nothing wider — an
     * "@" naming somebody outside the group resolves to nobody, so a
     * message can neither reach nor confirm the existence of a member the
     * writer could not otherwise see.
     */
    public function testSomebodyOutsideTheGroupIsNeverResolved(): void
    {
        $service = $this->service([], []);

        $this->assertSame([], $service->resolve($this->group, 'Bonjour @Akéla', 7));
    }

    /**
     * The reason names are matched longest-first: with both "Marie" and
     * "Marie Dupont" in the group, "@Marie Dupont" must name one person.
     */
    public function testALongerNameClaimsItsOwnCharactersFromAShorterOne(): void
    {
        $service = $this->service([4, 5], [4 => 'Marie', 5 => 'Marie Dupont']);

        $this->assertSame([5], $service->resolve($this->group, 'Coucou @Marie Dupont', 7));
    }

    public function testBothAreNamedWhenBothAreActuallyWritten(): void
    {
        $service = $this->service([4, 5], [4 => 'Marie', 5 => 'Marie Dupont']);

        $resolved = $service->resolve($this->group, '@Marie Dupont et @Marie, à samedi', 7);

        sort($resolved);
        $this->assertSame([4, 5], $resolved);
    }

    public function testMatchingIgnoresCase(): void
    {
        $service = $this->service([4], [4 => 'Akéla']);

        $this->assertSame([4], $service->resolve($this->group, 'merci @akéla', 7));
    }

    /**
     * A body with no "@" at all costs nothing: no membership query, no
     * name lookup.
     */
    public function testABodyWithoutAnAtNeverAsksTheGroupWhoIsInIt(): void
    {
        $resolver = $this->createMock(GroupRecipientResolver::class);
        $resolver->expects($this->never())->method('memberIdsFor');
        $memberService = $this->createMock(MemberService::class);
        $memberService->expects($this->never())->method('findDisplayNamesByMemberIds');

        $service = new MentionService($resolver, $memberService);

        $this->assertSame([], $service->resolve($this->group, 'Rendez-vous samedi', 7));
    }

    public function testItStopsAtTheCeilingPerMessage(): void
    {
        $names = [];
        $body = '';
        for ($i = 1; $i <= MentionService::MAX_PER_MESSAGE + 5; $i++) {
            $names[$i] = 'Membre' . $i;
            $body .= '@Membre' . $i . ' ';
        }
        $service = $this->service(array_keys($names), $names);

        $this->assertCount(MentionService::MAX_PER_MESSAGE, $service->resolve($this->group, $body, 7));
    }

    // ---- suggest (the composer's autocomplete) ----

    public function testSuggestMatchesAnyPartOfTheName(): void
    {
        $service = $this->service([4, 5], [4 => 'Marie Dupont', 5 => 'Baloo']);

        $this->assertSame(
            [['id' => 4, 'label' => 'Marie Dupont', 'mention' => 'Marie Dupont']],
            $service->suggest($this->group, 'dup', 7)
        );
    }

    public function testSuggestReturnsNothingForAnEmptyQuery(): void
    {
        $service = $this->service([4], [4 => 'Akéla']);

        $this->assertSame([], $service->suggest($this->group, '   ', 7));
    }

    public function testSuggestIsCappedAtItsLimit(): void
    {
        $names = [];
        for ($i = 1; $i <= 20; $i++) {
            $names[$i] = 'Membre' . $i;
        }
        $service = $this->service(array_keys($names), $names);

        $this->assertCount(3, $service->suggest($this->group, 'Membre', 7, 3));
    }

    // ---- Named by the account, like every other name in this module ----
    //
    // The identity service here is the REAL one, over real user_accounts
    // and member_years rows joined by the same blind index production
    // uses. The mocked service() above keeps exercising the path for a
    // group whose members have no account at all — still the common case.

    /**
     * @param array<int, array{first_name: string, totem?: string}> $members
     * @return array{service: MentionService, member_ids: int[], pdo: \PDO}
     */
    private function accountBackedService(array $seeds): array
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($pdo);

        $memberIds = [];
        foreach ($seeds as $seed) {
            $seeded = GroupsTestHelper::seedAccountWithMembers(
                $pdo,
                $seed['email'],
                $seed['first_name'],
                $seed['last_name'],
                $seed['members']
            );
            $memberIds = array_merge($memberIds, $seeded['member_ids']);
        }

        $resolver = $this->createMock(GroupRecipientResolver::class);
        $resolver->method('memberIdsFor')->willReturn($memberIds);

        $encryption = GroupsTestHelper::testEncryption();
        $memberService = new MemberService(
            new \Core\Import\MemberYearRepository($pdo),
            $encryption,
            \Core\Database\Connection::withPdo($pdo)
        );

        return [
            'service' => new MentionService($resolver, $memberService, GroupsTestHelper::identityService($pdo)),
            'member_ids' => $memberIds,
            'pdo' => $pdo,
        ];
    }

    /**
     * A group with no year of its own (an invitation group), so the
     * effective year passed in is the one that counts — the seeded rows
     * live in year 1. setUp()'s group is pinned to year 7 and would send
     * the membership lookup to a year nothing was seeded in.
     */
    private function yearlessGroup(): DiscussionGroup
    {
        return new DiscussionGroup(
            1, 'Louveteaux', null, 3, null, '2026-01-01 10:00:00', 1, '2026-01-01 09:00:00'
        );
    }

    public function testAnAtNamesTheHumanNotTheTotem(): void
    {
        $built = $this->accountBackedService([[
            'email' => 'marie@example.test',
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'members' => [['first_name' => 'Léa', 'totem' => 'Akéla']],
        ]]);

        $this->assertSame(
            $built['member_ids'],
            $built['service']->resolve($this->yearlessGroup(), 'Merci @Marie Dupont pour hier', 1)
        );
    }

    /**
     * Mentioning a parent of two reaches the parent — once — without the
     * writer having to know which of the two totems to type. Both
     * memberships come back because both are hers; the notification layer
     * collapses them onto her one account.
     */
    public function testMentioningAParentReachesEveryMembershipTheyHold(): void
    {
        $built = $this->accountBackedService([[
            'email' => 'marie@example.test',
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'members' => [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']],
        ]]);

        $found = $built['service']->resolve($this->yearlessGroup(), '@Marie Dupont tu viens ?', 1);

        sort($found);
        $expected = $built['member_ids'];
        sort($expected);
        $this->assertSame($expected, $found);
    }

    /**
     * The autocomplete SHOWS the memberships so two people can be told
     * apart, and INSERTS the account's name alone — nobody wants
     * "@Marie Dupont (Akéla, Baloo)" in the middle of a sentence, and
     * resolve() reads the short form back out of the stored body.
     */
    public function testTheAutocompleteShowsTheMembershipsAndInsertsTheAccountName(): void
    {
        $built = $this->accountBackedService([[
            'email' => 'marie@example.test',
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'members' => [['first_name' => 'Léa', 'totem' => 'Akéla'], ['first_name' => 'Tom', 'totem' => 'Baloo']],
        ]]);

        $rows = $built['service']->suggest($this->yearlessGroup(), 'dupont', 1);

        // ONE row for one human, not one per child.
        $this->assertCount(1, $rows);
        $this->assertSame('Marie Dupont (Akéla, Baloo)', $rows[0]['label']);
        $this->assertSame('Marie Dupont', $rows[0]['mention']);
    }

    /**
     * Typing the totem still finds the parent behind it: the memberships
     * are part of the rendered name, so the search matches them too.
     */
    public function testTheAutocompleteStillFindsSomebodyByTheirTotem(): void
    {
        $built = $this->accountBackedService([[
            'email' => 'marie@example.test',
            'first_name' => 'Marie',
            'last_name' => 'Dupont',
            'members' => [['first_name' => 'Léa', 'totem' => 'Akéla']],
        ]]);

        $rows = $built['service']->suggest($this->yearlessGroup(), 'akél', 1);

        $this->assertCount(1, $rows);
        $this->assertSame('Marie Dupont (Akéla)', $rows[0]['label']);
        $this->assertSame('Marie Dupont', $rows[0]['mention']);
    }

    /**
     * The cap counts PEOPLE, not memberships — otherwise one parent of
     * three would spend three of the ten a message is allowed.
     */
    public function testTheCapCountsPeopleAndNotMemberships(): void
    {
        $seeds = [];
        for ($i = 1; $i <= MentionService::MAX_PER_MESSAGE + 2; $i++) {
            $seeds[] = [
                'email' => 'parent' . $i . '@example.test',
                'first_name' => 'Parent',
                'last_name' => 'Numero' . $i,
                'members' => [['first_name' => 'A' . $i], ['first_name' => 'B' . $i]],
            ];
        }
        $built = $this->accountBackedService($seeds);

        $body = '';
        for ($i = 1; $i <= MentionService::MAX_PER_MESSAGE + 2; $i++) {
            $body .= '@Parent Numero' . $i . ' ';
        }

        $found = $built['service']->resolve($this->yearlessGroup(), $body, 1);

        // Ten humans named, two memberships each.
        $this->assertCount(MentionService::MAX_PER_MESSAGE * 2, $found);
    }
}
