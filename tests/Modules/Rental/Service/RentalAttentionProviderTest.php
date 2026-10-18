<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Attention\AttentionPoint;
use Core\Config\AppClock;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Service\RentalAttentionProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The Locations points of issue #718 § C: a landlord address that is empty
 * (point 1, one per asset) and a compliance entry already expired (point 7,
 * never before the date — the register's reminder covers that).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private RentalAssetRepository $assets;
    private RentalComplianceRepository $compliance;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RentalTestHelper::registerSettings($this->settings);
        $this->assets = new RentalAssetRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->compliance = new RentalComplianceRepository($this->pdo);
    }

    private function provider(): RentalAttentionProvider
    {
        return new RentalAttentionProvider($this->assets, $this->compliance, $this->settings);
    }

    private function asset(string $name, string $slug): int
    {
        return $this->assets->create('Local', $name, $slug, 20, 1, null, null, null, true);
    }

    /** @return AttentionPoint[] */
    private function pointsTitled(string $needle): array
    {
        return array_values(array_filter(
            $this->provider()->collect(1),
            static fn(AttentionPoint $p): bool => str_contains($p->title, $needle)
        ));
    }

    private function daysFromToday(int $days): string
    {
        return AppClock::now()->modify("{$days} days")->format('Y-m-d');
    }

    public function testNoAssetNoPoint(): void
    {
        $this->assertSame([], $this->provider()->collect(1));
    }

    public function testAnAssetWithNoLandlordAddressAnywhereIsUrgentAndLeadsToItsSettings(): void
    {
        $this->asset('Local Saint-Georges', 'local-saint-georges');

        $points = $this->pointsTitled('bailleur');

        $this->assertCount(1, $points);
        $this->assertSame("L'adresse du bailleur de « Local Saint-Georges » est vide", $points[0]->title);
        $this->assertStringContainsString('imprimeront « — »', $points[0]->why);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
        $this->assertSame('/mes-locations/local-saint-georges/reglages', $points[0]->actionUrl);
    }

    public function testTheUnitPostalAddressIsEnoughWhenNoOtherLandlordIsSet(): void
    {
        $this->asset('Local', 'local');
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, "Rue du Camp 1\n1000 Bruxelles");

        $this->assertSame([], $this->pointsTitled('bailleur'));
    }

    public function testTheAssetsOwnLandlordAddressIsEnough(): void
    {
        $id = $this->asset('Prairie', 'prairie');
        $this->assets->saveLandlord($id, 'ASBL Les Amis', 'Chemin 2, 5000 Namur', null);

        $this->assertSame([], $this->pointsTitled('bailleur'));
    }

    public function testOnePointPerAssetConcerned(): void
    {
        $this->asset('Local', 'local');
        $this->asset('Prairie', 'prairie');

        $this->assertCount(2, $this->pointsTitled('bailleur'));
    }

    public function testAnArchivedAssetRaisesNothing(): void
    {
        $id = $this->asset('Ancien local', 'ancien-local');
        $this->assets->setArchived($id, true);
        $this->compliance->create($id, 'Attestation incendie', null, $this->daysFromToday(-10), null);

        $this->assertSame([], $this->provider()->collect(1));
    }

    public function testAnExpiredComplianceEntryIsAnAttentionPointWithItsDate(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $id = $this->asset('Local', 'local');
        $expiredOn = $this->daysFromToday(-3);
        $this->compliance->create($id, 'Attestation incendie', null, $expiredOn, null);

        $points = $this->pointsTitled('a expiré');

        $this->assertCount(1, $points);
        $this->assertSame('« Attestation incendie » de « Local » a expiré', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame($expiredOn, $points[0]->dueDate?->format('Y-m-d'));
        $this->assertSame('/mes-locations/local/conformite', $points[0]->actionUrl);
    }

    /**
     * Today and later belong to the register's own reminder, which already
     * warns before the date.
     */
    public function testAnEntryNotYetExpiredRaisesNothing(): void
    {
        $this->settings->set(UnitAddresses::POSTAL_ADDRESS, 'Rue du Camp 1');
        $id = $this->asset('Local', 'local');
        $this->compliance->create($id, 'Expire aujourd\'hui', null, $this->daysFromToday(0), null);
        $this->compliance->create($id, 'Expire bientôt', null, $this->daysFromToday(5), null);
        $this->compliance->create($id, 'Sans échéance', null, null, null);

        $this->assertSame([], $this->provider()->collect(1));
    }
}
