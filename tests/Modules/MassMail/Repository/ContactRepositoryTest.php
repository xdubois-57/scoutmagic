<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\Contact;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * `mass_mail_block_contacts` against the test database: what is stored,
 * what can be asked of it while it is encrypted, the wholesale replacement
 * a file performs, and the one operation that deliberately ignores the
 * group and list boundaries.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ContactRepository $repository;
    private ListBlockRepository $blocks;
    private int $listAId;
    private int $listBId;
    private int $groupA1;
    private int $groupA2;
    private int $groupB;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->repository = new ContactRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->blocks = new ListBlockRepository($this->pdo);

        $this->listAId = $this->createList('Liste A');
        $this->listBId = $this->createList('Liste B');
        $this->groupA1 = $this->blocks->create($this->listAId, ListBlock::KIND_CONTACT_GROUP, 'Commune', null);
        $this->groupA2 = $this->blocks->create($this->listAId, ListBlock::KIND_CONTACT_GROUP, 'Paroisse', null);
        $this->groupB = $this->blocks->create($this->listBId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
    }

    private function createList(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description) VALUES (?, ?)');
        $stmt->execute([$name, 'Description.']);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Nothing readable ever reaches the columns. This is the guarantee
     * SECURITY.md §5 asks of every personal-data field, and the only
     * assertion that actually checks it is one that reads the raw bytes.
     */
    public function testNameAndAddressAreStoredEncryptedAndComeBackDecrypted(): void
    {
        $id = $this->repository->create($this->groupA1, 'Commune de Wavre', 'jeunesse@wavre.be');

        $stmt = $this->pdo->prepare(
            'SELECT name_encrypted, email_encrypted FROM mass_mail_block_contacts WHERE id = ?'
        );
        $stmt->execute([$id]);
        $raw = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('Commune de Wavre', (string) $raw['name_encrypted']);
        $this->assertStringNotContainsString('jeunesse@wavre.be', (string) $raw['email_encrypted']);

        $contact = $this->repository->findForBlock($this->groupA1)[0];
        $this->assertSame($id, $contact->id);
        $this->assertSame($this->groupA1, $contact->blockId);
        $this->assertSame('Commune de Wavre', $contact->name);
        $this->assertSame('jeunesse@wavre.be', $contact->email);
        $this->assertFalse($contact->isUnsubscribed());
    }

    public function testAContactWithoutANameIsStoredWithNoNameAtAll(): void
    {
        $this->repository->create($this->groupA1, null, 'cure@paroisse.be');

        $this->assertNull($this->repository->findForBlock($this->groupA1)[0]->name);
    }

    public function testTheDatabaseRefusesTheSameAddressTwiceInOneGroup(): void
    {
        $this->repository->create($this->groupA1, null, 'cure@paroisse.be');

        $this->expectException(\PDOException::class);
        $this->repository->create($this->groupA1, null, 'cure@paroisse.be');
    }

    public function testTheSameAddressMayBelongToTwoGroups(): void
    {
        $this->repository->create($this->groupA1, 'Curé', 'cure@paroisse.be');
        $this->repository->create($this->groupA2, 'Le curé', 'cure@paroisse.be');

        $this->assertCount(1, $this->repository->findForBlock($this->groupA1));
        $this->assertCount(1, $this->repository->findForBlock($this->groupA2));
    }

    /**
     * A count is the one thing SQL can answer about this table, and it is
     * why the list's page costs no decryption at all.
     */
    public function testCountingNeedsNoDecryption(): void
    {
        $this->repository->create($this->groupA1, null, 'un@test.be');
        $this->repository->create($this->groupA1, null, 'deux@test.be');
        $this->repository->create($this->groupA1, null, 'trois@test.be');
        $this->repository->create($this->groupA2, null, 'deux@test.be');
        $this->repository->create($this->groupB, null, 'quatre@test.be');
        $this->repository->unsubscribeEverywhere('deux@test.be');

        $this->assertSame(['total' => 3, 'unsubscribed' => 1], $this->repository->countForBlock($this->groupA1));
        $this->assertSame(
            [
                $this->groupA1 => ['total' => 3, 'unsubscribed' => 1],
                $this->groupA2 => ['total' => 1, 'unsubscribed' => 1],
            ],
            $this->repository->countByBlockForList($this->listAId)
        );
        // « deux » is in both groups of list A, and is one contact.
        $this->assertSame(
            ['total' => 3, 'unsubscribed' => 1],
            $this->repository->countDistinctForList($this->listAId)
        );
    }

    public function testAGroupOrAListWithoutContactsCountsZero(): void
    {
        $this->assertSame(['total' => 0, 'unsubscribed' => 0], $this->repository->countForBlock($this->groupB));
        $this->assertSame([], $this->repository->countByBlockForList($this->listBId));
        $this->assertSame(['total' => 0, 'unsubscribed' => 0], $this->repository->countDistinctForList($this->listBId));
    }

    public function testContactsComeBackSortedByNameThenAddress(): void
    {
        $this->repository->create($this->groupA1, 'Zoé', 'zoe@test.be');
        $this->repository->create($this->groupA1, 'Alice', 'alice@test.be');
        $this->repository->create($this->groupA1, null, 'sans-nom@test.be');

        $this->assertSame(
            ['sans-nom@test.be', 'alice@test.be', 'zoe@test.be'],
            array_map(fn(Contact $c) => $c->email, $this->repository->findForBlock($this->groupA1))
        );
    }

    /**
     * The contact half of a list: every group of THIS list, active rows
     * only, and an address in two groups once per group — the caller is
     * the one that names the blocks that bring somebody.
     */
    public function testTheActiveContactsOfAListSpanItsGroupsAndNothingElse(): void
    {
        $this->repository->create($this->groupA1, null, 'cure@paroisse.be');
        $this->repository->create($this->groupA2, null, 'cure@paroisse.be');
        $this->repository->create($this->groupA2, null, 'parti@test.be');
        $this->repository->create($this->groupB, null, 'ailleurs@test.be');
        $this->repository->unsubscribeEverywhere('parti@test.be');

        $active = $this->repository->findActiveForList($this->listAId);

        $this->assertSame(
            [['cure@paroisse.be', $this->groupA1], ['cure@paroisse.be', $this->groupA2]],
            array_map(fn(Contact $c) => [$c->email, $c->blockId], $active)
        );
    }

    /**
     * The unsubscribe ignores the group and list boundaries on purpose:
     * somebody asking that the unit stop writing to them is addressing the
     * unit, not whichever list the mail happened to come from.
     */
    public function testUnsubscribingReachesEveryGroupHoldingTheAddress(): void
    {
        $this->repository->create($this->groupA1, 'Curé', 'cure@paroisse.be');
        $this->repository->create($this->groupA2, 'Curé', 'cure@paroisse.be');
        $this->repository->create($this->groupB, 'Le curé', 'cure@paroisse.be');
        $this->repository->create($this->groupB, 'Commune', 'jeunesse@wavre.be');

        $this->assertSame(3, $this->repository->unsubscribeEverywhere('CURE@paroisse.be'));

        $this->assertSame(1, $this->repository->countForBlock($this->groupA1)['unsubscribed']);
        $this->assertSame(1, $this->repository->countForBlock($this->groupA2)['unsubscribed']);
        $this->assertSame(1, $this->repository->countForBlock($this->groupB)['unsubscribed']);
        $this->assertSame([], $this->repository->findActiveForList($this->listAId));
        $this->assertSame(
            ['jeunesse@wavre.be'],
            array_map(fn(Contact $c) => $c->email, $this->repository->findActiveForList($this->listBId))
        );
    }

    public function testUnsubscribingTwiceChangesNothingTheSecondTime(): void
    {
        $this->repository->create($this->groupA1, null, 'cure@paroisse.be');

        $this->assertSame(1, $this->repository->unsubscribeEverywhere('cure@paroisse.be'));
        $first = $this->repository->findForBlock($this->groupA1)[0]->unsubscribedAt;

        $this->assertSame(0, $this->repository->unsubscribeEverywhere('cure@paroisse.be'));
        $this->assertSame($first, $this->repository->findForBlock($this->groupA1)[0]->unsubscribedAt);
    }

    /**
     * The file's whole effect on a group: additions, a name corrected in
     * place, the rest removed — and an unsubscribed row neither removed
     * for being absent nor touched for being present.
     */
    public function testReplacingAGroupKeepsItsUnsubscribedRowsWhateverTheFileSays(): void
    {
        $this->repository->create($this->groupA1, 'Commune', 'jeunesse@wavre.be');
        $this->repository->create($this->groupA1, 'Ancien', 'retire@test.be');
        $this->repository->create($this->groupA1, 'Parti', 'parti@test.be');
        $this->repository->create($this->groupA1, 'Désinscrit présent', 'present@test.be');
        $this->repository->unsubscribeEverywhere('parti@test.be');
        $this->repository->unsubscribeEverywhere('present@test.be');
        $this->repository->create($this->groupA2, 'Ailleurs', 'retire@test.be');

        $summary = $this->repository->replaceForBlock($this->groupA1, [
            ['name' => 'Commune de Wavre', 'email' => 'jeunesse@wavre.be'],
            ['name' => 'Nouveau', 'email' => 'nouveau@test.be'],
            ['name' => 'Renommé', 'email' => 'present@test.be'],
            ['name' => 'Doublon', 'email' => 'NOUVEAU@test.be'],
        ]);

        $this->assertSame(['added' => 1, 'unchanged' => 2, 'removed' => 1, 'kept_unsubscribed' => 1], $summary);
        $contacts = [];
        foreach ($this->repository->findForBlock($this->groupA1) as $contact) {
            $contacts[$contact->email] = [$contact->name, $contact->isUnsubscribed()];
        }
        ksort($contacts);
        $this->assertSame([
            'jeunesse@wavre.be' => ['Commune de Wavre', false],
            'nouveau@test.be' => ['Nouveau', false],
            'parti@test.be' => ['Parti', true],
            'present@test.be' => ['Désinscrit présent', true],
        ], $contacts);
        // Another group's row is nobody's business here.
        $this->assertCount(1, $this->repository->findForBlock($this->groupA2));
    }

    /**
     * Creating a contact group is the block and its contacts together, so
     * the replacement must join a transaction the caller already holds
     * rather than fail to open its own.
     */
    public function testReplacingJoinsATransactionAlreadyOpen(): void
    {
        $this->pdo->beginTransaction();
        $this->repository->replaceForBlock($this->groupA1, [['name' => null, 'email' => 'un@test.be']]);
        $this->pdo->rollBack();

        $this->assertSame([], $this->repository->findForBlock($this->groupA1));
    }

    public function testOnlyTheUnsubscribedRowsAFileDoesNotCarryAreCountedAsSurviving(): void
    {
        $this->repository->create($this->groupA1, null, 'parti@test.be');
        $this->repository->create($this->groupA1, null, 'reste@test.be');
        $this->repository->create($this->groupA1, null, 'actif@test.be');
        $this->repository->unsubscribeEverywhere('parti@test.be');
        $this->repository->unsubscribeEverywhere('reste@test.be');

        $this->assertSame(2, $this->repository->countUnsubscribedNotIn($this->groupA1, []));
        $this->assertSame(1, $this->repository->countUnsubscribedNotIn($this->groupA1, [' RESTE@test.be']));
        $this->assertSame(0, $this->repository->countUnsubscribedNotIn($this->groupB, []));
    }
}
