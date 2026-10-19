<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Security\EncryptionService;
use Modules\Fees\Api\FeeAccuracySummaryProvider;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\FeeAccuracySummaryService;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * Api\FeeAccuracySummaryProvider — the three counts the "Année scoute"
 * workflow shows beside its fees step. They must be the page's own
 * buckets, for the year asked and no other.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeeAccuracySummaryServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private HouseholdService $households;
    private IgnoredHouseholdRepository $ignored;
    private FeeAccuracySummaryProvider $summary;
    private int $yearId;
    private int $otherYearId;
    private int $normalFeeId;
    private int $familyFeeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2027-2028', '2027-09-01', '2028-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');

        $this->households = new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption));
        $this->ignored = new IgnoredHouseholdRepository($this->pdo, $this->encryption);
        $this->summary = new FeeAccuracySummaryService(new FeeAccuracyService(
            $this->households,
            new HouseholdDetailRepository($this->pdo, $this->encryption),
            new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories),
            $this->ignored,
            $feeCategories
        ));
    }

    public function testAnEmptyYearCountsNothing(): void
    {
        $summary = $this->summary->summaryForYear($this->yearId);

        $this->assertSame(0, $summary->householdsToCorrect);
        $this->assertSame(0, $summary->membersWithoutAddress);
        $this->assertSame(0, $summary->householdsSetAside);
        $this->assertSame(0, $summary->householdsChecked);
    }

    public function testCountsTheThreeBucketsOfTheReport(): void
    {
        // To correct: three people at one address, each encoded « normale ».
        foreach (['Jean', 'Marie', 'Léa'] as $firstName) {
            $this->createMember($this->yearId, $firstName, 'Dupont', $this->normalFeeId, 'Rue de la Station', '5');
        }
        // Correct: three people encoded « famille ».
        foreach (['Paul', 'Anne', 'Zoé'] as $firstName) {
            $this->createMember($this->yearId, $firstName, 'Martin', $this->familyFeeId, 'Rue Haute', '12');
        }
        // Set aside: would be to correct, but a chef d'unité ignored it.
        foreach (['Tom', 'Eva', 'Max'] as $firstName) {
            $this->createMember($this->yearId, $firstName, 'Leroy', $this->normalFeeId, 'Rue Neuve', '3');
        }
        $blindIndex = $this->encryption->blindIndex(AddressNormalizer::normalize('Rue Neuve', '3', null, '1000'), 'address');
        $household = $this->households->householdsForYear($this->yearId)[$blindIndex];
        $this->ignored->ignore(
            $this->yearId,
            $blindIndex,
            FeeAccuracyService::compositionHash($household),
            'Garde alternée',
            null
        );
        // Not verifiable: no address at all.
        $this->createMember($this->yearId, 'Sans', 'Adresse', $this->normalFeeId, null, null);

        $summary = $this->summary->summaryForYear($this->yearId);

        $this->assertSame(1, $summary->householdsToCorrect);
        $this->assertSame(1, $summary->membersWithoutAddress);
        $this->assertSame(1, $summary->householdsSetAside);
        $this->assertSame(3, $summary->householdsChecked);
    }

    /**
     * The year is explicit: a household to correct in another year is not
     * this year's.
     */
    public function testReadsTheYearAskedOnly(): void
    {
        foreach (['Jean', 'Marie', 'Léa'] as $firstName) {
            $this->createMember($this->otherYearId, $firstName, 'Dupont', $this->normalFeeId, 'Rue de la Station', '5');
        }

        $this->assertSame(0, $this->summary->summaryForYear($this->yearId)->householdsToCorrect);
        $this->assertSame(1, $this->summary->summaryForYear($this->otherYearId)->householdsToCorrect);
    }

    private function createMember(
        int $scoutYearId,
        string $firstName,
        string $lastName,
        int $feeCategoryId,
        ?string $street,
        ?string $number
    ): void {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, fee_category_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $feeCategoryId,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        if ($street === null || $number === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted,
                                           postal_code_encrypted, city_encrypted, address_normalized_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'Domicile',
            $this->encryption->encrypt($street, 'member_addresses.street'),
            $this->encryption->encrypt($number, 'member_addresses.number'),
            $this->encryption->encrypt('1000', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Bruxelles', 'member_addresses.city'),
            $this->encryption->blindIndex(AddressNormalizer::normalize($street, $number, null, '1000'), 'address'),
        ]);
    }
}
