<?php

declare(strict_types=1);

namespace Tests\Core\Member\Household;

use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Member\HouseholdFeeCategory;
use Core\Security\EncryptionService;
use Modules\Registration\Api\HouseholdRegistrationCountProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two counts the core has to keep apart: what Desk contains today
 * (what the federation bills) and what it will contain once the known
 * movements are encoded.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HouseholdServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private HouseholdService $service;
    private int $scoutYearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new HouseholdService(new HouseholdRepository($this->pdo), $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    /** @return int member_year id */
    private function createMember(
        ?string $street,
        ?string $number,
        ?string $box,
        ?string $postalCode,
        bool $leaving = false,
        bool $active = true,
        ?int $scoutYearId = null,
        bool $withAddressRow = true
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, leaving, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId ?? $this->scoutYearId, 'enc', 'enc', $leaving ? 1 : 0, $active ? 1 : 0]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        if ($withAddressRow) {
            $this->addAddress($memberYearId, $street, $number, $box, $postalCode);
        }

        return $memberYearId;
    }

    private function addAddress(int $memberYearId, ?string $street, ?string $number, ?string $box, ?string $postalCode): void
    {
        $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
        $blindIndex = $normalized === '' ? null : $this->encryption->blindIndex($normalized, 'address');

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, address_normalized_blind_index) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, 'Domicile', $blindIndex]);
    }

    public function testDeskCountKeepsAMemberMarkedLeavingAndTheProjectionDoesNot(): void
    {
        $staying = $this->createMember('Rue de la Station', '5', null, '1000');
        $going = $this->createMember('Rue de la Station', '5', null, '1000', leaving: true);

        $household = $this->service->householdAt('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(2, $household->deskSize());
        $this->assertSame(1, $household->projectedSize());
        $this->assertSame([$going], $household->leavingMemberYearIds());
        $this->assertEqualsCanonicalizing([$staying, $going], $household->memberYearIds());
    }

    public function testDeskCategoryIsWhatTheFederationBillsAndTheProjectionIsWhatItWillBill(): void
    {
        $this->createMember('Avenue Louise', '10', null, '1050');
        $this->createMember('Avenue Louise', '10', null, '1050');
        $this->createMember('Avenue Louise', '10', null, '1050', leaving: true);

        $household = $this->service->householdAt('Avenue Louise', '10', null, '1050', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(HouseholdFeeCategory::FAMILY, $household->deskCategory());
        $this->assertSame(HouseholdFeeCategory::COUPLE, $household->projectedCategory());
        $this->assertTrue($household->categoriesDiffer());
    }

    public function testTwoCountsCanDifferWithoutTheCategoryMoving(): void
    {
        $this->createMember('Rue Haute', '1', null, '1000');
        $this->createMember('Rue Haute', '1', null, '1000');
        $this->createMember('Rue Haute', '1', null, '1000');
        $this->createMember('Rue Haute', '1', null, '1000', leaving: true);

        $household = $this->service->householdAt('Rue Haute', '1', null, '1000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(4, $household->deskSize());
        $this->assertSame(3, $household->projectedSize());
        // Four and three are both "famille" — nothing to correct, and a
        // screen must not offer this household as an action.
        $this->assertFalse($household->categoriesDiffer());
    }

    public function testAnInactiveMemberIsInNeitherCount(): void
    {
        $this->createMember('Rue Neuve', '2', null, '1000');
        $this->createMember('Rue Neuve', '2', null, '1000', active: false);

        $household = $this->service->householdAt('Rue Neuve', '2', null, '1000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(1, $household->deskSize());
        $this->assertSame(1, $household->projectedSize());
    }

    public function testAnotherScoutYearIsNotCounted(): void
    {
        $this->createMember('Rue du Marché', '3', null, '5000');
        $this->createMember('Rue du Marché', '3', null, '5000', scoutYearId: $this->otherYearId);

        $household = $this->service->householdAt('Rue du Marché', '3', null, '5000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(1, $household->deskSize());
    }

    public function testAnUnusableAddressIsNullRatherThanAHouseholdOfNobody(): void
    {
        $this->assertNull($this->service->householdAt(null, null, null, null, $this->scoutYearId));
        $this->assertNull($this->service->householdAt('', '', '', '', $this->scoutYearId));
    }

    public function testARealAddressNobodyLivesAtIsAnEmptyHouseholdNotNull(): void
    {
        $household = $this->service->householdAt('Rue Inconnue', '1', null, '9999', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(0, $household->deskSize());
        $this->assertSame(0, $household->projectedSize());
    }

    public function testHouseholdsForYearGroupsByNormalizedAddress(): void
    {
        $a1 = $this->createMember('Rue de la Station', '5', null, '1000');
        $a2 = $this->createMember('R. Station', '5', null, '1000'); // same address, different encoding
        $b1 = $this->createMember('Avenue Louise', '10', null, '1050');

        $households = $this->service->householdsForYear($this->scoutYearId);

        $this->assertCount(2, $households);
        $sizes = array_map(static fn($h): int => $h->deskSize(), array_values($households));
        sort($sizes);
        $this->assertSame([1, 2], $sizes);

        $stationIndex = $this->encryption->blindIndex(
            AddressNormalizer::normalize('Rue de la Station', '5', null, '1000'),
            'address'
        );
        $this->assertArrayHasKey($stationIndex, $households);
        $this->assertEqualsCanonicalizing([$a1, $a2], $households[$stationIndex]->memberYearIds());
        $this->assertNotContains($b1, $households[$stationIndex]->memberYearIds());
    }

    public function testHouseholdsForYearIgnoresRowsWithNoUsableBlindIndex(): void
    {
        $this->createMember('Rue de la Station', '5', null, '1000');
        $this->createMember(null, null, null, null); // address row with a NULL blind index

        $households = $this->service->householdsForYear($this->scoutYearId);

        $this->assertCount(1, $households);
    }

    public function testMembersWithNoUsableAddressAreReportedSeparately(): void
    {
        $this->createMember('Rue de la Station', '5', null, '1000');
        $noAddressRow = $this->createMember(null, null, null, null, withAddressRow: false);
        $unusableAddress = $this->createMember(null, null, null, null);

        $orphans = $this->service->memberYearIdsWithoutUsableAddress($this->scoutYearId);

        $this->assertEqualsCanonicalizing([$noAddressRow, $unusableAddress], $orphans);
    }

    public function testAMemberWithTwoDistinctAddressesBelongsToTwoHouseholds(): void
    {
        $memberYearId = $this->createMember('Rue de la Station', '5', null, '1000');
        $this->addAddress($memberYearId, 'Avenue du Parc', '12', null, '1050');

        $households = $this->service->householdsForYear($this->scoutYearId);

        $this->assertCount(2, $households);
        foreach ($households as $household) {
            $this->assertSame([$memberYearId], $household->memberYearIds());
        }
    }

    public function testAcceptedRegistrationsRaiseTheProjectionAndNeverTheDeskCount(): void
    {
        $this->createMember('Rue de la Station', '5', null, '1000');
        $service = new HouseholdService(
            new HouseholdRepository($this->pdo),
            $this->encryption,
            $this->providerReturning(2)
        );

        $household = $service->householdAt('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(1, $household->deskSize());
        $this->assertSame(3, $household->projectedSize());
        $this->assertSame(HouseholdFeeCategory::NORMAL, $household->deskCategory());
        $this->assertSame(HouseholdFeeCategory::FAMILY, $household->projectedCategory());
    }

    public function testHouseholdsForYearAsksTheRegistrationProviderOnceForEveryAddress(): void
    {
        $this->createMember('Rue de la Station', '5', null, '1000');
        $this->createMember('Avenue Louise', '10', null, '1050');

        $provider = new class implements HouseholdRegistrationCountProvider {
            public int $batchCalls = 0;
            public int $singleCalls = 0;

            public function countAtAddress(string $addressBlindIndex, int $scoutYearId, ?int $excludeRequestId): int
            {
                $this->singleCalls++;

                return 1;
            }

            public function countsAtAddresses(array $addressBlindIndexes, int $scoutYearId): array
            {
                $this->batchCalls++;

                return array_fill_keys($addressBlindIndexes, 1);
            }
        };
        $service = new HouseholdService(new HouseholdRepository($this->pdo), $this->encryption, $provider);

        $households = $service->householdsForYear($this->scoutYearId);

        $this->assertSame(1, $provider->batchCalls);
        $this->assertSame(0, $provider->singleCalls);
        foreach ($households as $household) {
            $this->assertSame(2, $household->projectedSize());
        }
    }

    public function testWithoutTheRegistrationModuleNobodyArrives(): void
    {
        $this->createMember('Rue de la Station', '5', null, '1000', leaving: true);

        $household = $this->service->householdAt('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertNotNull($household);
        $this->assertSame(0, $household->incomingRegistrations);
        $this->assertSame(1, $household->deskSize());
        $this->assertSame(0, $household->projectedSize());
    }

    public function testHouseholdsForYearOnAnEmptyYearAsksTheProviderNothing(): void
    {
        $provider = $this->providerReturning(3);
        $service = new HouseholdService(new HouseholdRepository($this->pdo), $this->encryption, $provider);

        $this->assertSame([], $service->householdsForYear($this->scoutYearId));
    }

    private function providerReturning(int $count): HouseholdRegistrationCountProvider
    {
        return new class ($count) implements HouseholdRegistrationCountProvider {
            public function __construct(private int $count)
            {
            }

            public function countAtAddress(string $addressBlindIndex, int $scoutYearId, ?int $excludeRequestId): int
            {
                return $this->count;
            }

            public function countsAtAddresses(array $addressBlindIndexes, int $scoutYearId): array
            {
                return array_fill_keys($addressBlindIndexes, $this->count);
            }
        };
    }
}
