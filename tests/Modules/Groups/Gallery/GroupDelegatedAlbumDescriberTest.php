<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Gallery;

use Core\Member\SectionMembershipRepository;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Gallery\GroupDelegatedAlbumDescriber;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Service\GroupAccessService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * What an administrator reads in gallery's list of albums taking space on
 * a storage location, for a group's album.
 *
 * This describer has worked correctly since it was written and had no test
 * of its own — which is how issue #749 could report the camps side as a
 * surprise while this one was quietly the reference implementation of the
 * same contract. Two of that issue's acceptance criteria are about
 * precisely this class continuing to behave: the group's CURRENT name, and
 * following a rename with no album recreated and no media moved.
 *
 * `tests/Modules` is registered recursively in `phpunit.xml`, so this new
 * directory needs no entry of its own (AGENTS.md's rule is about a
 * directory nothing in that file reaches).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GroupDelegatedAlbumDescriberTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groups;
    private GroupDelegatedAlbumDescriber $describer;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->groups = new GroupRepository($this->pdo);

        // A REAL file-ownership checker, not a double: what the first
        // test below asserts is that the describer and that checker claim
        // the same owner type, and a double would let them agree about a
        // string this test chose rather than the one the module uses.
        $resolver = $this->createStub(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear(1, '2025-2026', null));
        $this->describer = new GroupDelegatedAlbumDescriber(
            new GroupFileOwnershipChecker(
                $this->groups,
                new GroupAccessService(
                    new GroupMemberRepository($this->pdo),
                    new GroupSectionRepository($this->pdo),
                    new SectionMembershipRepository($this->pdo)
                ),
                $resolver
            ),
            $this->groups
        );
    }

    /**
     * Keyed through the file-ownership checker rather than a constant of
     * its own, so the two can never end up claiming different owner types
     * — the describer's own docblock says so, and this is what holds it.
     */
    public function testItClaimsTheSameOwnerTypeAsTheModulesFileGate(): void
    {
        $this->assertTrue($this->describer->supports('discussion_group'));
        $this->assertFalse($this->describer->supports('camp_camp'));
    }

    public function testAGroupsAlbumIsNamedAfterTheGroup(): void
    {
        $groupId = $this->groups->create("Chefs d'unité", 1, null, 1);

        $this->assertSame("Groupe Chefs d'unité", $this->describer->describe($groupId));
    }

    /**
     * The dynamic half, which issue #749 asks for by name: the label is
     * computed from the group as it stands now, so a rename moves it on
     * the next page load. Nothing is copied into gallery's row — which is
     * also why no album is recreated and no medium moves. There is
     * nothing to keep in step.
     */
    public function testRenamingTheGroupMovesTheLabel(): void
    {
        $groupId = $this->groups->create('Staff', 1, null, 1);
        $this->groups->rename($groupId, "Staff d'unité");

        $this->assertSame("Groupe Staff d'unité", $this->describer->describe($groupId));
    }

    /**
     * A group purged while its album survived. Saying nothing is the right
     * answer: the registry turns it into « Propriétaire supprimé », which
     * is what tells an administrator this album is theirs to clean up. A
     * name invented here would say the opposite.
     */
    public function testAnAlbumWhoseGroupHasBeenPurgedIsNotNamed(): void
    {
        $this->assertNull($this->describer->describe(4242));
    }
}
