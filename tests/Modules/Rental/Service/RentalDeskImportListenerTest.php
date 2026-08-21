<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Service\RentalDeskImportListener;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Roadmap §6.3: at every Desk import, a manager grant whose member no
 * longer appears is **marked inactive, never deleted**, and journaled; the
 * member's return reactivates it. Same shape as
 * Core\Import\MappingResolver::deactivateAllSections().
 */
class RentalDeskImportListenerTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetManagerRepository $managerRepository;
    private RentalDeskImportListener $listener;
    /** @var array<int, array{category: string, type: string, level: string, description: string, context: ?string}> */
    private array $journalEntries = [];

    private const YEAR = 7;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);

        $entries = &$this->journalEntries;
        $journalRepository = new class ($entries) extends JournalRepository {
            /** @param array<int, array{category: string, type: string, level: string, description: string, context: ?string}> $entries */
            public function __construct(private array &$entries)
            {
                // No parent::__construct(): this stub records calls instead
                // of writing to a database.
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
                $this->entries[] = compact('category', 'type', 'level', 'description', 'context');
            }
        };

        $this->listener = new RentalDeskImportListener(
            $this->managerRepository,
            new JournalService($journalRepository)
        );
    }

    private function insertAsset(string $slug): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', ucfirst($slug), $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testAManagerStillOnTheRosterKeepsTheirGrant(): void
    {
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);

        $this->listener->onDeskImportCompleted(self::YEAR, [1, 2, 3]);

        $this->assertTrue($this->managerRepository->findByAssetAndMember($asset, 1)?->isActive);
        $this->assertSame([], $this->journalEntries, 'A no-op import must not journal anything.');
    }

    public function testAManagerOffTheRosterIsDeactivatedNeverDeleted(): void
    {
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, true);

        $this->listener->onDeskImportCompleted(self::YEAR, [2, 3]);

        $manager = $this->managerRepository->findByAssetAndMember($asset, 1);
        $this->assertNotNull($manager, 'The grant is the record of who was responsible — it is never deleted.');
        $this->assertFalse($manager->isActive);
        $this->assertTrue($manager->isRenterContact, 'Deactivation must not quietly rewrite unrelated flags.');
        $this->assertSame([], $this->managerRepository->findActiveAssetIdsForMember(1));
    }

    public function testAReturningManagerIsReactivated(): void
    {
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);

        $this->listener->onDeskImportCompleted(self::YEAR, [2]);
        $this->assertFalse($this->managerRepository->findByAssetAndMember($asset, 1)?->isActive);

        $this->listener->onDeskImportCompleted(self::YEAR, [1, 2]);
        $this->assertTrue($this->managerRepository->findByAssetAndMember($asset, 1)?->isActive);
    }

    public function testEveryGrantOfADepartedManagerIsDeactivatedAcrossAllAssets(): void
    {
        $hall = $this->insertAsset('local');
        $ground = $this->insertAsset('terrain');
        $this->managerRepository->grant($hall, 1, false);
        $this->managerRepository->grant($ground, 1, false);
        $this->managerRepository->grant($hall, 2, false);

        $this->listener->onDeskImportCompleted(self::YEAR, [2]);

        $this->assertSame([], $this->managerRepository->findActiveAssetIdsForMember(1));
        $this->assertSame([$hall], $this->managerRepository->findActiveAssetIdsForMember(2));
    }

    public function testAnEmptyRosterDeactivatesEveryGrant(): void
    {
        // A botched import producing an empty roster suspends everything —
        // and, because nothing is deleted, one correct import restores it.
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);
        $this->managerRepository->grant($asset, 2, false);

        $this->listener->onDeskImportCompleted(self::YEAR, []);
        $this->assertSame([], $this->managerRepository->findAllActiveMemberIds());

        $this->listener->onDeskImportCompleted(self::YEAR, [1, 2]);
        $this->assertSame([1, 2], $this->managerRepository->findAllActiveMemberIds());
    }

    public function testDeactivationIsJournaledAsACountAndNeverAsAMemberId(): void
    {
        // A quiet mass deactivation is exactly what an admin needs to notice
        // after a botched import — but "who manages what" must not become
        // readable as personal data in the journal (SECURITY.md §5).
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);
        $this->managerRepository->grant($asset, 2, false);

        $this->listener->onDeskImportCompleted(self::YEAR, []);

        $this->assertCount(1, $this->journalEntries);
        $entry = $this->journalEntries[0];
        $this->assertSame('rental', $entry['category']);
        $this->assertSame('rental_managers_deactivated', $entry['type']);
        $this->assertSame('security', $entry['level']);
        $this->assertStringContainsString('2', $entry['description']);

        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(['count' => 2, 'scout_year_id' => self::YEAR], $context);
        $this->assertArrayNotHasKey('member_ids', $context);
    }

    public function testReactivationIsJournaledAtInfoLevel(): void
    {
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);
        $this->listener->onDeskImportCompleted(self::YEAR, []);
        $this->journalEntries = [];

        $this->listener->onDeskImportCompleted(self::YEAR, [1]);

        $this->assertCount(1, $this->journalEntries);
        $this->assertSame('rental_managers_reactivated', $this->journalEntries[0]['type']);
        $this->assertSame('info', $this->journalEntries[0]['level']);
    }

    public function testRunningTheSameImportTwiceIsIdempotentAndSilentTheSecondTime(): void
    {
        $asset = $this->insertAsset('local');
        $this->managerRepository->grant($asset, 1, false);

        $this->listener->onDeskImportCompleted(self::YEAR, [2]);
        $firstCount = count($this->journalEntries);

        $this->listener->onDeskImportCompleted(self::YEAR, [2]);

        $this->assertCount($firstCount, $this->journalEntries, 'Nothing changed, so nothing is journaled.');
        $this->assertFalse($this->managerRepository->findByAssetAndMember($asset, 1)?->isActive);
    }

    public function testAnInstallationWithNoGrantsIsANoOp(): void
    {
        $this->listener->onDeskImportCompleted(self::YEAR, [1, 2, 3]);

        $this->assertSame([], $this->journalEntries);
    }
}
