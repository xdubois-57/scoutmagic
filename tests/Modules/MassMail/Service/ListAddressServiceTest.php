<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * The rules a list's own addresses obey: what is a valid address, what a
 * duplicate is, what the cap refuses, and what an unsubscribed row is
 * allowed to become (nothing).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListAddressServiceTest extends TestCase
{
    private \PDO $pdo;
    private ListAddressService $service;
    private ListAddressRepository $repository;
    private SettingRepository $settingRepository;
    private SettingService $settings;
    private int $listId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);

        $this->repository = new ListAddressRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);
        $this->service = new ListAddressService(
            $this->repository,
            new MailingListRepository($this->pdo),
            $this->settings,
            $this->createMock(JournalService::class)
        );

        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description) VALUES (?, ?)');
        $stmt->execute(['Ma liste', 'Description.']);
        $this->listId = (int) $this->pdo->lastInsertId();
    }

    public function testAddingNormalisesTheAddressAndKeepsTheName(): void
    {
        $address = $this->service->add($this->listId, '  Commune de Wavre  ', '  Jeunesse@Wavre.BE ');

        $this->assertSame('Commune de Wavre', $address->name);
        $this->assertSame('jeunesse@wavre.be', $address->email);
    }

    public function testAnEmptyNameIsNoNameRatherThanAnEmptyOne(): void
    {
        $this->assertNull($this->service->add($this->listId, '   ', 'cure@paroisse.be')->name);
    }

    public function testAnInvalidAddressIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('Adresse email invalide.');
        $this->service->add($this->listId, null, 'pas-une-adresse');
    }

    public function testTheSameAddressTwiceInOneListIsRefusedBeforeTheDatabaseHasTo(): void
    {
        $this->service->add($this->listId, null, 'cure@paroisse.be');

        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('Cette adresse figure déjà dans la liste.');
        $this->service->add($this->listId, null, 'CURE@paroisse.be');
    }

    public function testAddingToAnUnknownListIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('Liste introuvable.');
        $this->service->add(9999, null, 'cure@paroisse.be');
    }



    /**
     * D3: an unsubscribed row survives everything. It is not editable and
     * not deletable — an unsubscribe a chief can undo with two clicks is
     * not an unsubscribe.
     */
    public function testAnUnsubscribedAddressIsNotDeletable(): void
    {
        $address = $this->service->add($this->listId, null, 'cure@paroisse.be');
        $this->service->unsubscribeEverywhere('cure@paroisse.be');

        try {
            $this->service->remove($address->id);
            $this->fail('deleting an unsubscribed address should be refused');
        } catch (MailingListException $e) {
            $this->assertStringContainsString('désinscrite', $e->getMessage());
        }

        $this->assertNotNull($this->service->findById($address->id));
    }

    public function testRemovingAnAddressDropsIt(): void
    {
        $address = $this->service->add($this->listId, null, 'cure@paroisse.be');

        $this->service->remove($address->id);

        $this->assertNull($this->service->findById($address->id));
    }

    public function testRemovingAnUnknownAddressIsRefused(): void
    {
        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('Adresse introuvable.');
        $this->service->remove(9999);
    }

    public function testOnlyActiveAddressesAreOfferedForSending(): void
    {
        $this->service->add($this->listId, null, 'active@test.be');
        $this->service->add($this->listId, null, 'partie@test.be');
        $this->service->unsubscribeEverywhere('partie@test.be');

        $this->assertSame(
            ['active@test.be'],
            array_map(fn($a) => $a->email, $this->service->findActiveForList($this->listId))
        );
        $this->assertSame(['total' => 2, 'unsubscribed' => 1], $this->service->countForList($this->listId));
    }

    public function testTheCapRefusesTheAddressThatWouldExceedIt(): void
    {
        $this->registerMax(2);
        $this->service->add($this->listId, null, 'un@test.be');
        $this->service->add($this->listId, null, 'deux@test.be');

        $this->expectException(MailingListException::class);
        $this->expectExceptionMessage('ne peut pas dépasser 2 adresses');
        $this->service->add($this->listId, null, 'trois@test.be');
    }

    /**
     * The cap is asked BEFORE anything is written — a limit discovered
     * halfway through is a list half replaced.
     */
    public function testTheCapIsAskedForAWholeBatchAtOnce(): void
    {
        $this->registerMax(3);
        $this->service->add($this->listId, null, 'un@test.be');

        $this->service->assertRoomFor($this->listId, 2);

        $this->expectException(MailingListException::class);
        $this->service->assertRoomFor($this->listId, 3);
    }

    public function testTheCapFallsBackToItsDefaultWhenTheSettingIsAbsentOrAbsurd(): void
    {
        $this->assertSame(2000, $this->service->maxAddresses());

        $this->registerMax(0);
        $this->assertSame(2000, $this->service->maxAddresses());
    }

    private function registerMax(int $value): void
    {
        $this->settings->register(
            ListAddressService::SETTING_MAX_ADDRESSES,
            (string) $value,
            'number',
            'Adresses par liste de diffusion (maximum)',
            'Plafond.',
            'mass_mail'
        );
        $this->settings->clearCache();
    }
}
