<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalAssetManager;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

class RentalAssetManagerRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetManagerRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->repository = new RentalAssetManagerRepository($this->pdo);
    }

    private function insertAsset(string $slug): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)'
        );
        $stmt->execute(['Local', ucfirst($slug), $slug]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testGrantCreatesAnActiveAssociation(): void
    {
        $asset = $this->insertAsset('local');

        $this->repository->grant($asset, 42, true);

        $manager = $this->repository->findByAssetAndMember($asset, 42);
        $this->assertNotNull($manager);
        $this->assertSame($asset, $manager->assetId);
        $this->assertSame(42, $manager->memberId);
        $this->assertTrue($manager->isRenterContact);
        $this->assertTrue($manager->isActive);
    }

    public function testGrantingTwiceUpdatesTheSameRowRatherThanDuplicating(): void
    {
        $asset = $this->insertAsset('local');

        $first = $this->repository->grant($asset, 42, false);
        $second = $this->repository->grant($asset, 42, true);

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->repository->findAllByAsset($asset));
        $this->assertTrue($this->repository->findByAssetAndMember($asset, 42)?->isRenterContact);
    }

    public function testGrantReactivatesAPreviouslyDeactivatedAssociation(): void
    {
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 42, false);
        $this->repository->deactivateForMembers([42]);

        $this->repository->grant($asset, 42, false);

        $this->assertTrue($this->repository->findByAssetAndMember($asset, 42)?->isActive);
    }

    public function testSeveralManagersPerAssetAndSeveralAssetsPerManager(): void
    {
        $hall = $this->insertAsset('local');
        $ground = $this->insertAsset('terrain');

        $this->repository->grant($hall, 1, false);
        $this->repository->grant($hall, 2, false);
        $this->repository->grant($ground, 1, false);

        $this->assertCount(2, $this->repository->findAllByAsset($hall));
        $this->assertCount(1, $this->repository->findAllByAsset($ground));

        $memberOneAssets = $this->repository->findActiveAssetIdsForMember(1);
        sort($memberOneAssets);
        $expected = [$hall, $ground];
        sort($expected);
        $this->assertSame($expected, $memberOneAssets);
        $this->assertSame([$hall], $this->repository->findActiveAssetIdsForMember(2));
    }

    public function testFindActiveAssetIdsIgnoresDeactivatedGrants(): void
    {
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 42, false);
        $this->repository->deactivateForMembers([42]);

        $this->assertSame([], $this->repository->findActiveAssetIdsForMember(42));
        // The row itself survives — deactivation is never a delete.
        $this->assertNotNull($this->repository->findByAssetAndMember($asset, 42));
    }

    public function testFindActiveAssetIdsForMembersDeduplicatesAcrossMembers(): void
    {
        // One email can be linked to several members; both may manage the
        // same asset, and the asset must appear once.
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 1, false);
        $this->repository->grant($asset, 2, false);

        $this->assertSame([$asset], $this->repository->findActiveAssetIdsForMembers([1, 2]));
        $this->assertSame([], $this->repository->findActiveAssetIdsForMembers([]));
    }

    public function testFindAllByAssetCanIncludeDeactivatedGrants(): void
    {
        // The configuration page shows them so an admin can see and act on
        // an association an import suspended.
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 1, false);
        $this->repository->grant($asset, 2, false);
        $this->repository->deactivateForMembers([2]);

        $this->assertCount(1, $this->repository->findAllByAsset($asset, true));
        $this->assertCount(2, $this->repository->findAllByAsset($asset, false));
    }

    public function testDeactivateAndReactivateReportTheRowsTheyActuallyChanged(): void
    {
        // The count is what the journal reports, so it must be the real
        // number of changed rows, not the number of ids passed in.
        $hall = $this->insertAsset('local');
        $ground = $this->insertAsset('terrain');
        $this->repository->grant($hall, 1, false);
        $this->repository->grant($ground, 1, false);
        $this->repository->grant($hall, 2, false);

        $this->assertSame(2, $this->repository->deactivateForMembers([1]));
        // Already inactive: nothing left to change.
        $this->assertSame(0, $this->repository->deactivateForMembers([1]));
        // Never granted anything: nothing to change either.
        $this->assertSame(0, $this->repository->deactivateForMembers([99]));

        $this->assertSame(2, $this->repository->reactivateForMembers([1]));
        $this->assertSame(0, $this->repository->reactivateForMembers([1]));
    }

    public function testFindActiveAndInactiveMemberIdsPartitionTheGrants(): void
    {
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 1, false);
        $this->repository->grant($asset, 2, false);
        $this->repository->deactivateForMembers([2]);

        $this->assertSame([1], $this->repository->findAllActiveMemberIds());
        $this->assertSame([2], $this->repository->findAllInactiveMemberIds());
    }

    public function testFindRenterContactsReturnsOnlyActiveFlaggedManagers(): void
    {
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 1, true);
        $this->repository->grant($asset, 2, false);
        $this->repository->grant($asset, 3, true);
        $this->repository->deactivateForMembers([3]);

        $contacts = $this->repository->findRenterContactsByAsset($asset);

        $this->assertSame([1], array_map(fn(RentalAssetManager $m) => $m->memberId, $contacts));
    }

    public function testRevokeDeletesTheGrantOutright(): void
    {
        // A chief's deliberate removal is a real deletion, unlike the
        // import-driven deactivation.
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 42, false);

        $this->repository->revoke($asset, 42);

        $this->assertNull($this->repository->findByAssetAndMember($asset, 42));
        $this->assertSame([], $this->repository->findAllActiveMemberIds());
        $this->assertSame([], $this->repository->findAllInactiveMemberIds());
    }

    public function testSetRenterContactTogglesTheFlagWithoutTouchingActivity(): void
    {
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 42, false);
        $this->repository->deactivateForMembers([42]);

        $this->repository->setRenterContact($asset, 42, true);

        $manager = $this->repository->findByAssetAndMember($asset, 42);
        $this->assertTrue($manager?->isRenterContact);
        $this->assertFalse($manager?->isActive, 'Flipping the contact flag must not silently reactivate a grant.');
    }

    public function testDeletingAnAssetCascadesToItsGrants(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $asset = $this->insertAsset('local');
        $this->repository->grant($asset, 42, false);

        $this->pdo->prepare('DELETE FROM rental_assets WHERE id = ?')->execute([$asset]);

        $this->assertNull($this->repository->findByAssetAndMember($asset, 42));
    }
}
