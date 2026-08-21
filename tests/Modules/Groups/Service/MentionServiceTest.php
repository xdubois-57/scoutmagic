<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Service\GroupRecipientResolver;
use Modules\Groups\Service\MentionService;
use PHPUnit\Framework\TestCase;

/**
 * "@Akéla" turned into the member it names — resolved from the stored
 * body against the group's own membership, never from anything a client
 * sent.
 */
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
            [['id' => 4, 'label' => 'Marie Dupont']],
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
}
