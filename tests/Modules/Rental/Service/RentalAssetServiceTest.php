<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

class RentalAssetServiceTest extends TestCase
{
    private RentalAssetRepository $assetRepository;
    private RentalAssetService $service;
    /** @var array<int, array{type: string, description: string, context: ?string}> */
    private array $journalEntries = [];

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($pdo);

        $this->assetRepository = new RentalAssetRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{type: string, description: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
                $this->entries[] = compact('type', 'description', 'context');
            }
        };

        $this->service = new RentalAssetService(
            $this->assetRepository,
            new RentalSlugGenerator($this->assetRepository),
            new JournalService($journalRepository)
        );
    }

    private function create(string $name = 'Local Saint-Georges'): int
    {
        return $this->service->create($name, 'Local', 60, 1, '17:00', '11:00', null, true, false);
    }

    public function testCreateMintsASlugFromTheName(): void
    {
        $id = $this->create();

        $this->assertSame('local-saint-georges', $this->assetRepository->findById($id)?->slug);
    }

    public function testCreateDisambiguatesASlugCollision(): void
    {
        $this->create('Le local');
        $second = $this->create('Le local');

        $this->assertSame('le-local-2', $this->assetRepository->findById($second)?->slug);
    }

    public function testTheSlugIsStableAcrossARename(): void
    {
        // The point of a stable slug: a renter's existing link must keep
        // working after the asset is renamed.
        $id = $this->create('Local Saint-Georges');

        $this->service->updateGeneral($id, 'Local Saint-Georges (rénové)', 'Local', 60, 1, null, null, null, true, false);

        $asset = $this->assetRepository->findById($id);
        $this->assertSame('local-saint-georges', $asset?->slug);
        $this->assertSame('Local Saint-Georges (rénové)', $asset?->name);
    }

    public function testCreateRejectsAnEmptyNameOrType(): void
    {
        $this->expectException(RentalException::class);
        $this->service->create('   ', 'Local', null, 1, null, null, null, false, false);
    }

    public function testCreateRejectsAnEmptyType(): void
    {
        $this->expectException(RentalException::class);
        $this->service->create('Local', '  ', null, 1, null, null, null, false, false);
    }

    public function testCreateRejectsANonPositiveQuantityOrCapacity(): void
    {
        try {
            $this->service->create('Local', 'Local', null, 0, null, null, null, false, false);
            $this->fail('A quantity below 1 must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('quantité', $e->getMessage());
        }

        try {
            $this->service->create('Local', 'Local', 0, 1, null, null, null, false, false);
            $this->fail('A capacity below 1 must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('capacité', $e->getMessage());
        }
    }

    public function testCreateAcceptsANullCapacityAsNotApplicable(): void
    {
        // A trailer has no capacity — that is not the same as zero.
        $id = $this->service->create('Remorque', 'Remorque', null, 1, null, null, null, false, false);

        $this->assertNull($this->assetRepository->findById($id)?->capacity);
    }

    public function testCreateRejectsAMalformedTime(): void
    {
        $this->expectException(RentalException::class);
        $this->service->create('Local', 'Local', null, 1, '25:99', null, null, false, false);
    }

    public function testNormalizeTimeAcceptsSecondsAndDropsThem(): void
    {
        // A browser <input type="time"> can submit HH:MM:SS; the stored
        // value must be one shape whatever the browser sent.
        $this->assertSame('17:00', RentalAssetService::normalizeTime('17:00:00'));
        $this->assertSame('17:05', RentalAssetService::normalizeTime('17:05'));
        $this->assertSame('00:00', RentalAssetService::normalizeTime('00:00'));
        $this->assertSame('23:59', RentalAssetService::normalizeTime('23:59'));
        $this->assertNull(RentalAssetService::normalizeTime(''));
        $this->assertNull(RentalAssetService::normalizeTime('  '));
        $this->assertNull(RentalAssetService::normalizeTime(null));
        $this->assertNull(RentalAssetService::normalizeTime('24:00'));
        $this->assertNull(RentalAssetService::normalizeTime('7:00'));
        $this->assertNull(RentalAssetService::normalizeTime('nope'));
    }

    public function testArchiveAndRestoreToggleTheFlag(): void
    {
        $id = $this->create();

        $this->service->archive($id);
        $this->assertTrue($this->assetRepository->findById($id)?->isArchived);

        $this->service->restore($id);
        $this->assertFalse($this->assetRepository->findById($id)?->isArchived);
    }

    public function testDeleteRemovesAnAssetWithNoHistory(): void
    {
        $id = $this->create();

        $this->service->delete($id);

        $this->assertNull($this->assetRepository->findById($id));
    }

    public function testEveryOperationRefusesAnUnknownAsset(): void
    {
        foreach ([
            fn() => $this->service->archive(999),
            fn() => $this->service->restore(999),
            fn() => $this->service->delete(999),
            fn() => $this->service->updateGeneral(999, 'X', 'Local', null, 1, null, null, null, false, false),
            fn() => $this->service->requireAsset(999),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An unknown asset must be refused.');
            } catch (RentalException $e) {
                $this->assertStringContainsString("n'existe pas", $e->getMessage());
            }
        }
    }

    public function testTheJournalRecordsTheAssetNameButNeverTheEmergencyPhone(): void
    {
        // The asset's name is a chief's label for a building, not personal
        // data. The emergency phone reaches a named human and must never
        // appear in the journal (SECURITY.md §5).
        $id = $this->service->create(
            'Local Saint-Georges',
            'Local',
            60,
            1,
            null,
            null,
            '+32 470 12 34 56',
            true,
            false
        );
        $this->service->updateGeneral($id, 'Local Saint-Georges', 'Local', 60, 1, null, null, '+32 471 00 00 00', true, false);
        $this->service->archive($id);

        $this->assertNotSame([], $this->journalEntries);
        foreach ($this->journalEntries as $entry) {
            $serialized = $entry['description'] . ' ' . (string) $entry['context'];
            $this->assertStringNotContainsString('470', $serialized);
            $this->assertStringNotContainsString('471', $serialized);
            $this->assertStringNotContainsString('+32', $serialized);
        }

        $types = array_column($this->journalEntries, 'type');
        $this->assertSame(['rental_asset_created', 'rental_asset_updated', 'rental_asset_archived'], $types);
        $this->assertStringContainsString('Local Saint-Georges', $this->journalEntries[0]['description']);
    }

    public function testUpdateGeneralTrimsTheNameAndType(): void
    {
        $id = $this->create();

        $this->service->updateGeneral($id, '  Terrain  ', '  Terrain  ', null, 2, null, null, null, false, true);

        $asset = $this->assetRepository->findById($id);
        $this->assertSame('Terrain', $asset?->name);
        $this->assertSame('Terrain', $asset?->assetType);
    }

    public function testHasHistoryIsFalseWhileNothingRecordsWhatHappened(): void
    {
        // Honest rather than a stub pretending to check: bookings arrive in
        // a later iteration, and each history-bearing table extends this one
        // gate.
        $id = $this->create();

        $this->assertFalse($this->service->hasHistory($id));
    }
}
