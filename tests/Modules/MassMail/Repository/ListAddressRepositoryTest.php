<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ListAddressRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * `mass_mail_list_addresses` against the test database: what is stored,
 * what can be asked of it while it is encrypted, and the one operation
 * that deliberately ignores the list boundary.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListAddressRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ListAddressRepository $repository;
    private int $listAId;
    private int $listBId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->repository = new ListAddressRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->listAId = $this->createList('Liste A');
        $this->listBId = $this->createList('Liste B');
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
        $id = $this->repository->create($this->listAId, 'Commune de Wavre', 'jeunesse@wavre.be');

        $stmt = $this->pdo->prepare('SELECT name_encrypted, email_encrypted FROM mass_mail_list_addresses WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('Commune de Wavre', (string) $raw['name_encrypted']);
        $this->assertStringNotContainsString('jeunesse@wavre.be', (string) $raw['email_encrypted']);

        $address = $this->repository->findById($id);
        $this->assertNotNull($address);
        $this->assertSame('Commune de Wavre', $address->name);
        $this->assertSame('jeunesse@wavre.be', $address->email);
        $this->assertFalse($address->isUnsubscribed());
    }

    public function testAnAddressWithoutANameIsStoredWithNoNameAtAll(): void
    {
        $id = $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->assertNull($this->repository->findById($id)?->name);
    }

    /**
     * The blind index is what makes an exact-match question answerable on
     * a column nothing can read — and it is asked on the NORMALISED
     * address, so « Cure@Paroisse.BE » and « cure@paroisse.be » are the
     * same person.
     */
    public function testTheSameAddressIsRecognisedWhateverItsCaseOrSpacing(): void
    {
        $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->assertTrue($this->repository->existsInList($this->listAId, '  Cure@Paroisse.BE  '));
        $this->assertFalse($this->repository->existsInList($this->listBId, 'cure@paroisse.be'));
    }

    public function testAnAddressBeingEditedDoesNotCollideWithItself(): void
    {
        $id = $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->assertTrue($this->repository->existsInList($this->listAId, 'cure@paroisse.be'));
        $this->assertFalse($this->repository->existsInList($this->listAId, 'cure@paroisse.be', $id));
    }

    public function testTheDatabaseRefusesTheSameAddressTwiceInOneList(): void
    {
        $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->expectException(\PDOException::class);
        $this->repository->create($this->listAId, null, 'cure@paroisse.be');
    }

    public function testTheSameAddressMayBelongToTwoDifferentLists(): void
    {
        $this->repository->create($this->listAId, 'Curé', 'cure@paroisse.be');
        $this->repository->create($this->listBId, 'Le curé', 'cure@paroisse.be');

        $this->assertCount(1, $this->repository->findForList($this->listAId));
        $this->assertCount(1, $this->repository->findForList($this->listBId));
    }

    /**
     * A count is the one thing SQL can answer about this table, and it is
     * why the screen's collapsed summary costs no decryption at all.
     */
    public function testCountingNeedsNoDecryption(): void
    {
        $this->repository->create($this->listAId, null, 'un@test.be');
        $this->repository->create($this->listAId, null, 'deux@test.be');
        $this->repository->create($this->listAId, null, 'trois@test.be');
        $this->repository->unsubscribeEverywhere('deux@test.be');

        $this->assertSame(
            ['total' => 3, 'unsubscribed' => 1],
            $this->repository->countForList($this->listAId)
        );
        $this->assertSame(['total' => 0, 'unsubscribed' => 0], $this->repository->countForList($this->listBId));
    }

    public function testAddressesComeBackSortedByNameThenAddress(): void
    {
        $this->repository->create($this->listAId, 'Zoé', 'zoe@test.be');
        $this->repository->create($this->listAId, 'Alice', 'alice@test.be');
        $this->repository->create($this->listAId, null, 'sans-nom@test.be');

        $this->assertSame(
            ['sans-nom@test.be', 'alice@test.be', 'zoe@test.be'],
            array_map(fn($a) => $a->email, $this->repository->findForList($this->listAId))
        );
    }

    /**
     * The unsubscribe ignores the list boundary on purpose: somebody
     * asking that the unit stop writing to them is addressing the unit,
     * not whichever list the mail happened to come from.
     */
    public function testUnsubscribingReachesEveryListHoldingTheAddress(): void
    {
        $this->repository->create($this->listAId, 'Curé', 'cure@paroisse.be');
        $this->repository->create($this->listBId, 'Le curé', 'cure@paroisse.be');
        $this->repository->create($this->listBId, 'Commune', 'jeunesse@wavre.be');

        $this->assertSame(2, $this->repository->unsubscribeEverywhere('CURE@paroisse.be'));

        $this->assertSame(1, $this->repository->countForList($this->listAId)['unsubscribed']);
        $this->assertSame(1, $this->repository->countForList($this->listBId)['unsubscribed']);
        $this->assertCount(0, $this->repository->findActiveForList($this->listAId));
        $this->assertSame(
            ['jeunesse@wavre.be'],
            array_map(fn($a) => $a->email, $this->repository->findActiveForList($this->listBId))
        );
    }

    public function testUnsubscribingTwiceChangesNothingTheSecondTime(): void
    {
        $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->assertSame(1, $this->repository->unsubscribeEverywhere('cure@paroisse.be'));
        $first = $this->repository->findForList($this->listAId)[0]->unsubscribedAt;

        $this->assertSame(0, $this->repository->unsubscribeEverywhere('cure@paroisse.be'));
        $this->assertSame($first, $this->repository->findForList($this->listAId)[0]->unsubscribedAt);
    }

    public function testUpdatingAnAddressRewritesItsBlindIndexToo(): void
    {
        $id = $this->repository->create($this->listAId, 'Commune', 'ancienne@wavre.be');

        $this->repository->update($id, 'Commune de Wavre', 'nouvelle@wavre.be');

        $this->assertFalse($this->repository->existsInList($this->listAId, 'ancienne@wavre.be'));
        $this->assertTrue($this->repository->existsInList($this->listAId, 'nouvelle@wavre.be'));
        $this->assertSame('Commune de Wavre', $this->repository->findById($id)?->name);
    }

    public function testDeletingRemovesTheRow(): void
    {
        $id = $this->repository->create($this->listAId, null, 'cure@paroisse.be');

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
        $this->assertSame(0, $this->repository->countForList($this->listAId)['total']);
    }
}
