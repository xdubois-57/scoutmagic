<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

class RentalAssetRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetRepository $repository;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalAssetRepository($this->pdo, $this->encryption);
    }

    private function createAsset(
        string $name = 'Local Saint-Georges',
        string $slug = 'local-saint-georges',
        bool $isPublic = true,
        int $quantity = 1,
        ?string $phone = null
    ): int {
        return $this->repository->create(
            'Local',
            $name,
            $slug,
            60,
            $quantity,
            '17:00',
            '11:00',
            $phone,
            $isPublic
        );
    }

    public function testCreateAndFindByIdRoundTripsEveryField(): void
    {
        $id = $this->createAsset(phone: '+32 470 12 34 56');

        $asset = $this->repository->findById($id);

        $this->assertNotNull($asset);
        $this->assertSame('Local', $asset->assetType);
        $this->assertSame('Local Saint-Georges', $asset->name);
        $this->assertSame('local-saint-georges', $asset->slug);
        $this->assertSame(60, $asset->capacity);
        $this->assertSame(1, $asset->quantity);
        $this->assertSame('17:00', $asset->arrivalTime);
        $this->assertSame('11:00', $asset->departureTime);
        $this->assertSame('+32 470 12 34 56', $asset->emergencyPhone);
        $this->assertFalse($asset->isArchived);
        $this->assertTrue($asset->isPublic);
    }

    public function testTheEmergencyPhoneIsStoredEncryptedNotInClear(): void
    {
        // The one piece of personal data in this module's schema
        // (SECURITY.md §5). A grep of the raw column must not find it.
        $id = $this->createAsset(phone: '+32 470 12 34 56');

        $stored = $this->pdo->query('SELECT emergency_phone_encrypted FROM rental_assets')->fetchColumn();

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('470', $stored);
        $this->assertStringNotContainsString('+32', $stored);
        $this->assertSame(
            '+32 470 12 34 56',
            $this->encryption->decrypt($stored, 'rental_assets.emergency_phone')
        );
        $this->assertSame('+32 470 12 34 56', $this->repository->findById($id)?->emergencyPhone);
    }

    public function testAnAbsentEmergencyPhoneStaysNullRatherThanEncryptedEmptyString(): void
    {
        $id = $this->createAsset(phone: null);
        $this->assertNull($this->repository->findById($id)?->emergencyPhone);
        $this->assertNull($this->pdo->query('SELECT emergency_phone_encrypted FROM rental_assets')->fetchColumn());

        // A whitespace-only submission is the same as none — otherwise the
        // renter's page shows an "emergency number" that is a blank line.
        $blankId = $this->repository->create('Tente', 'T', 't', null, 1, null, null, '   ', false);
        $this->assertNull($this->repository->findById($blankId)?->emergencyPhone);
    }

    public function testFindBySlugReturnsTheAssetIncludingWhenArchived(): void
    {
        // Archived assets stay findable: their managers still reach them.
        // Filtering happens at the caller, which knows who is asking.
        $id = $this->createAsset();
        $this->repository->setArchived($id, true);

        $asset = $this->repository->findBySlug('local-saint-georges');
        $this->assertNotNull($asset);
        $this->assertTrue($asset->isArchived);
    }

    public function testFindBySlugReturnsNullForAnUnknownSlug(): void
    {
        $this->assertNull($this->repository->findBySlug('nope'));
    }

    public function testFindAllPublicExcludesPrivateAndArchivedAssets(): void
    {
        $public = $this->createAsset('Public', 'public', isPublic: true);
        $this->createAsset('Privé', 'prive', isPublic: false);
        $archived = $this->createAsset('Archivé', 'archive', isPublic: true);
        $this->repository->setArchived($archived, true);

        $names = array_map(fn($a) => $a->name, $this->repository->findAllPublic());

        $this->assertSame(['Public'], $names);
        $this->assertNotNull($this->repository->findById($public));
    }

    public function testFindAllIncludesArchivedAssetsAndSortsThemLast(): void
    {
        $this->createAsset('Bravo', 'bravo');
        $archived = $this->createAsset('Alpha', 'alpha');
        $this->repository->setArchived($archived, true);

        $names = array_map(fn($a) => $a->name, $this->repository->findAll());

        $this->assertSame(['Bravo', 'Alpha'], $names);
    }

    public function testFindAllActiveExcludesArchivedAssets(): void
    {
        $this->createAsset('Actif', 'actif');
        $archived = $this->createAsset('Archivé', 'archive');
        $this->repository->setArchived($archived, true);

        $this->assertSame(['Actif'], array_map(fn($a) => $a->name, $this->repository->findAllActive()));
    }

    public function testFindAllByIdsReturnsOnlyTheRequestedAssets(): void
    {
        $a = $this->createAsset('A', 'a');
        $this->createAsset('B', 'b');
        $c = $this->createAsset('C', 'c');

        $names = array_map(fn($x) => $x->name, $this->repository->findAllByIds([$a, $c]));

        $this->assertSame(['A', 'C'], $names);
        $this->assertSame([], $this->repository->findAllByIds([]));
    }

    public function testSlugExistsCountsArchivedAssetsAndHonoursTheException(): void
    {
        // An archived asset keeps its slug reserved: an old public link must
        // never be silently repointed at a different asset.
        $id = $this->createAsset();
        $this->repository->setArchived($id, true);

        $this->assertTrue($this->repository->slugExists('local-saint-georges'));
        $this->assertFalse($this->repository->slugExists('local-saint-georges', $id));
        $this->assertFalse($this->repository->slugExists('autre-chose'));
    }

    public function testUpdateGeneralWritesEveryGeneralField(): void
    {
        $id = $this->createAsset(phone: '+32 470 00 00 00');

        $this->repository->updateGeneral(
            $id,
            'Terrain',
            'Terrain de la Sablière',
            'local-saint-georges',
            120,
            3,
            '14:00',
            '10:00',
            '+32 471 99 99 99',
            false
        );

        $asset = $this->repository->findById($id);
        $this->assertNotNull($asset);
        $this->assertSame('Terrain', $asset->assetType);
        $this->assertSame('Terrain de la Sablière', $asset->name);
        $this->assertSame(120, $asset->capacity);
        $this->assertSame(3, $asset->quantity);
        $this->assertSame('14:00', $asset->arrivalTime);
        $this->assertSame('+32 471 99 99 99', $asset->emergencyPhone);
        $this->assertFalse($asset->isPublic);
    }

    public function testSetArchivedTogglesBothWays(): void
    {
        $id = $this->createAsset();

        $this->repository->setArchived($id, true);
        $this->assertTrue($this->repository->findById($id)?->isArchived);

        $this->repository->setArchived($id, false);
        $this->assertFalse($this->repository->findById($id)?->isArchived);
    }

    public function testDeleteRemovesTheAsset(): void
    {
        $id = $this->createAsset();

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testIsStockDistinguishesASingleAssetFromCountableStock(): void
    {
        $single = $this->repository->findById($this->createAsset('Local', 'local-1', quantity: 1));
        $stock = $this->repository->findById($this->createAsset('Tentes', 'tentes', quantity: 8));

        $this->assertFalse($single?->isStock());
        $this->assertTrue($stock?->isStock());
    }
}
