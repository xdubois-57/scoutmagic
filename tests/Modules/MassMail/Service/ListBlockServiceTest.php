<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * The blocks of a custom list and the three rules that make one valid:
 * a member group carries at least one choice, a contact group a name, and
 * a block never changes kind.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListBlockServiceTest extends TestCase
{
    private \PDO $pdo;
    private ListBlockService $service;
    private ListBlockRepository $blocks;
    private ContactRepository $contacts;
    private MailingListRepository $lists;
    private int $listId;
    private int $functionId;
    private int $sectionId;
    private int $badgeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->blocks = new ListBlockRepository($this->pdo);
        $this->contacts = new ContactRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->lists = new MailingListRepository($this->pdo);
        $this->service = new ListBlockService(
            $this->blocks,
            $this->contacts,
            $this->lists,
            $this->createStub(JournalService::class)
        );

        $this->listId = $this->lists->create('Staff élargi', 'Description.', null);
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $this->functionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO badges (name) VALUES ('Trésorier')");
        $this->badgeId = (int) $this->pdo->lastInsertId();
    }

    /** One axis is enough: the others read « Peu importe » and constrain nothing. */
    public function testAMemberGroupNeedsOneChoiceOnOneAxisOnly(): void
    {
        $block = $this->service->createMemberGroup($this->listId, [], [], [$this->badgeId], 4);

        $this->assertTrue($block->isMemberGroup());
        $this->assertNull($block->name);
        $this->assertSame(4, $block->createdBy);
        $this->assertSame([$this->badgeId], $block->badgeIds);
        $this->assertSame([], $block->functionIds);
    }

    /**
     * §2.1: a member group without any choice designates nobody to the
     * resolution and « everybody » to anyone reading three « Peu
     * importe » — refused by the server, not only greyed on the screen.
     */
    public function testAMemberGroupWithoutAnyChoiceIsRefused(): void
    {
        try {
            $this->service->createMemberGroup($this->listId, [], [], [], null);
            $this->fail('a member group without any choice must be refused');
        } catch (MailingListException $e) {
            $this->assertSame('Choisissez au moins une fonction, une section ou un badge.', $e->getMessage());
        }

        $this->assertSame([], $this->service->findForList($this->listId));
    }

    public function testIdsThatAreNotIdsDoNotCountAsAChoice(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createMemberGroup($this->listId, [0, -3], [], [], null);
    }

    public function testAMemberGroupOnAnUnknownListIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->createMemberGroup(999, [$this->functionId], [], [], null);
    }

    public function testUpdatingAMemberGroupReplacesItsCriteria(): void
    {
        $block = $this->service->createMemberGroup($this->listId, [$this->functionId], [$this->sectionId], [], null);

        $updated = $this->service->updateMemberGroup($block->id, [], [$this->sectionId], [$this->badgeId], null);

        $this->assertSame([], $updated->functionIds);
        $this->assertSame([$this->sectionId], $updated->sectionIds);
        $this->assertSame([$this->badgeId], $updated->badgeIds);
    }

    public function testUpdatingAMemberGroupToNoChoiceIsRefusedAndChangesNothing(): void
    {
        $block = $this->service->createMemberGroup($this->listId, [$this->functionId], [], [], null);

        try {
            $this->service->updateMemberGroup($block->id, [], [], [], null);
            $this->fail('emptying a member group must be refused');
        } catch (MailingListException) {
        }

        $this->assertSame([$this->functionId], $this->service->findById($block->id)?->functionIds);
    }

    /** §2.1: a block's kind is immutable — a contact group has no criteria to receive. */
    public function testAContactGroupCannotBeUpdatedAsAMemberGroup(): void
    {
        $id = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);

        $this->expectException(MailingListException::class);
        $this->service->updateMemberGroup($id, [$this->functionId], [], [], null);
    }

    public function testUpdatingAnUnknownBlockIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->updateMemberGroup(999, [$this->functionId], [], [], null);
    }

    /** Either kind is removed the same way, its rows with it. */
    public function testRemovingAContactGroupTakesItsContactsAndSaysWhatItWas(): void
    {
        $id = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $this->contacts->create($id, null, 'cure@paroisse.be');
        $this->journalMock()->expects($this->once())->method('log')->with(
            'mass_mail',
            'list_block_deleted',
            'info',
            'Groupe de contacts retiré d\'une liste de diffusion',
            ['list_id' => $this->listId, 'block_id' => $id, 'kind' => ListBlock::KIND_CONTACT_GROUP],
            7
        );

        $removed = $this->service->delete($id, 7);

        $this->assertSame('Partenaires', $removed->name);
        $this->assertNull($this->service->findById($id));
        $this->assertSame(['total' => 0, 'unsubscribed' => 0], $this->contacts->countForBlock($id));
    }

    public function testRemovingAnUnknownBlockIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->service->delete(999, null);
    }

    public function testBlocksAreCountedByKindForThePageOfLists(): void
    {
        $this->service->createMemberGroup($this->listId, [$this->functionId], [], [], null);
        $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);

        $this->assertSame(
            [$this->listId => ['member_group' => 1, 'contact_group' => 1]],
            $this->service->countKindsByList()
        );
    }

    public function testTheContactsOfAGroupAreReadOnlyAndOnlyAGroupHasThem(): void
    {
        $id = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $this->contacts->create($id, 'Curé', 'cure@paroisse.be');

        $this->assertSame(['cure@paroisse.be'], array_map(fn($c) => $c->email, $this->service->findContacts($id)));

        $member = $this->service->createMemberGroup($this->listId, [$this->functionId], [], [], null);
        $this->expectException(MailingListException::class);
        $this->service->findContacts($member->id);
    }

    public function testAContactGroupNameIsTrimmedAndBounded(): void
    {
        $this->assertSame('Partenaires', $this->service->contactGroupName('  Partenaires '));
        $this->assertSame(str_repeat('é', 150), $this->service->contactGroupName(str_repeat('é', 150)));

        $this->expectException(MailingListException::class);
        $this->service->contactGroupName(str_repeat('é', 151));
    }

    /** The journal says how many rows, and never which address (SECURITY.md §11). */
    public function testTheUnsubscribeIsJournalledByCountOnly(): void
    {
        $a = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'A', null);
        $b = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'B', null);
        $this->contacts->create($a, null, 'cure@paroisse.be');
        $this->contacts->create($b, null, 'cure@paroisse.be');
        $this->journalMock()->expects($this->once())->method('log')->with(
            'mass_mail',
            'list_contact_unsubscribed',
            'info',
            $this->anything(),
            ['rows' => 2]
        );

        $this->assertSame(2, $this->service->unsubscribeEverywhere('cure@paroisse.be'));
        $this->assertSame(0, $this->service->unsubscribeEverywhere('cure@paroisse.be'));
    }

    /** Rebuilds the service over a journal this test sets expectations on. */
    private function journalMock(): JournalService&\PHPUnit\Framework\MockObject\MockObject
    {
        $journal = $this->createMock(JournalService::class);
        $this->service = new ListBlockService($this->blocks, $this->contacts, $this->lists, $journal);

        return $journal;
    }
}
