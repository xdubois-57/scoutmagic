<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * `mass_mail_list_blocks` and a member group's three criteria junctions,
 * against the test database. The ordering and the cascades are judged
 * again on the production engine by ListBlocksOnMysqlTest.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListBlockRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ListBlockRepository $repository;
    private MailingListRepository $lists;
    private int $listId;
    private int $functionA;
    private int $functionB;
    private int $sectionId;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->repository = new ListBlockRepository($this->pdo);
        $this->lists = new MailingListRepository($this->pdo);
        $this->listId = $this->lists->create('Staff élargi', 'Description.', null);

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $this->functionA = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('TRES', 'Trésorier', 'chief')");
        $this->functionB = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO badges (name) VALUES ('Secouriste')");
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    public function testAMemberGroupComesBackWithItsCriteria(): void
    {
        $id = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria(
            $id,
            [$this->functionB, $this->functionA, $this->functionA],
            [$this->sectionId],
            [$this->badgeId]
        );

        $block = $this->repository->findById($id);

        $this->assertNotNull($block);
        $this->assertTrue($block->isMemberGroup());
        $this->assertNull($block->name);
        $this->assertSame([$this->functionA, $this->functionB], $block->functionIds);
        $this->assertSame([$this->sectionId], $block->sectionIds);
        $this->assertSame([$this->badgeId], $block->badgeIds);
    }

    public function testReplacingCriteriaReplacesEveryAxis(): void
    {
        $id = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($id, [$this->functionA], [$this->sectionId], [$this->badgeId]);

        $this->repository->replaceCriteria($id, [$this->functionB], [], []);

        $block = $this->repository->findById($id);
        $this->assertSame([$this->functionB], $block?->functionIds);
        $this->assertSame([], $block?->sectionIds);
        $this->assertSame([], $block?->badgeIds);
    }

    public function testAContactGroupHasANameAndNoCriteria(): void
    {
        $id = $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', 7);

        $block = $this->repository->findById($id);

        $this->assertNotNull($block);
        $this->assertTrue($block->isContactGroup());
        $this->assertSame('Partenaires', $block->name);
        $this->assertSame(7, $block->createdBy);
        $this->assertNull($block->fileReplacedAt);
        $this->assertSame([], $block->functionIds);
    }

    public function testRenamingAndStampingTheFile(): void
    {
        $id = $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);

        $this->repository->rename($id, 'Partenaires locaux');
        $this->repository->markFileReplaced($id, new \DateTimeImmutable('2026-09-12 10:15:00'));

        $block = $this->repository->findById($id);
        $this->assertSame('Partenaires locaux', $block?->name);
        $this->assertSame('2026-09-12 10:15:00', $block?->fileReplacedAt);
    }

    public function testAnUnknownBlockIsNull(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    /**
     * Member groups first, then contact groups, each by creation —
     * whatever order they were created in, and each with its own criteria
     * although they are read for the whole list at once.
     */
    public function testAListsBlocksComeBackRankedByKindThenCreation(): void
    {
        $contactFirst = $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'B', null);
        $memberFirst = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $contactSecond = $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'A', null);
        $memberSecond = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($memberFirst, [$this->functionA], [], []);
        $this->repository->replaceCriteria($memberSecond, [], [], [$this->badgeId]);
        $otherList = $this->lists->create('Autre', 'Description.', null);
        $this->repository->create($otherList, ListBlock::KIND_MEMBER_GROUP, null, null);

        $blocks = $this->repository->findForList($this->listId);

        $this->assertSame(
            [$memberFirst, $memberSecond, $contactFirst, $contactSecond],
            array_map(fn(ListBlock $b) => $b->id, $blocks)
        );
        $this->assertSame([$this->functionA], $blocks[0]->functionIds);
        $this->assertSame([], $blocks[0]->badgeIds);
        $this->assertSame([$this->badgeId], $blocks[1]->badgeIds);
        $this->assertSame([], $this->repository->findForList($this->lists->create('Vide', 'D.', null)));
    }

    public function testKindsAreCountedPerListForTheWholePage(): void
    {
        $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $otherList = $this->lists->create('Autre', 'Description.', null);
        $this->repository->create($otherList, ListBlock::KIND_CONTACT_GROUP, 'Commune', null);
        $this->lists->create('Vide', 'Description.', null);

        $this->assertSame(
            [
                $this->listId => ['member_group' => 2, 'contact_group' => 1],
                $otherList => ['member_group' => 0, 'contact_group' => 1],
            ],
            $this->repository->countKindsByList()
        );
    }

    public function testDeletingABlockTakesItsCriteriaAndContactsWithIt(): void
    {
        $member = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($member, [$this->functionA], [$this->sectionId], [$this->badgeId]);
        $contacts = $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $this->pdo->exec(
            "INSERT INTO mass_mail_block_contacts (block_id, email_encrypted, email_blind_index)
             VALUES ({$contacts}, 'x', 'idx')"
        );

        $this->repository->delete($member);
        $this->repository->delete($contacts);

        $this->assertSame([], $this->repository->findForList($this->listId));
        foreach (['mass_mail_block_functions', 'mass_mail_block_sections', 'mass_mail_block_badges',
            'mass_mail_block_contacts'] as $table) {
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }
    }

    /** Deleting the list empties every table under it, children first. */
    public function testDeletingTheListTakesItsBlocksWithIt(): void
    {
        $member = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($member, [$this->functionA], [], []);
        $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);

        $this->lists->delete($this->listId);

        $this->assertNull($this->lists->findById($this->listId));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_list_blocks')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_block_functions')->fetchColumn());
    }

    /**
     * Every section and badge any member group names, whatever the list —
     * what keeps a deactivated one in the pickers, and what the badge
     * deletion guard asks.
     */
    public function testReferencedSectionsAndBadgesSpanEveryList(): void
    {
        $a = $this->repository->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($a, [], [$this->sectionId], [$this->badgeId]);
        $otherList = $this->lists->create('Autre', 'D.', null);
        $b = $this->repository->create($otherList, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->repository->replaceCriteria($b, [], [$this->sectionId], []);

        $this->assertSame([$this->sectionId], $this->repository->findReferencedSectionIds());
        $this->assertSame([$this->badgeId], $this->repository->findReferencedBadgeIds());
    }

    public function testAtomicallyRollsBackEverythingOnFailure(): void
    {
        try {
            $this->repository->atomically(function (): void {
                $this->repository->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Perdu', null);
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception must go through');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->repository->findForList($this->listId));
        $this->assertFalse($this->pdo->inTransaction());
    }
}
