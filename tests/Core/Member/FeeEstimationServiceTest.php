<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\AddressNormalizer;
use Core\Member\FeeEstimationRepository;
use Core\Member\FeeEstimationService;
use Core\Member\HouseholdFeeCategory;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class FeeEstimationServiceTest extends TestCase
{
    private \PDO $pdo;
    private FeeEstimationService $service;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new FeeEstimationService(new FeeEstimationRepository($this->pdo), $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int member_year id
     */
    private function createMemberAtAddress(string $street, string $number, ?string $box, string $postalCode, bool $leaving = false): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, leaving) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc', $leaving ? 1 : 0]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $normalized = AddressNormalizer::normalize($street, $number, $box, $postalCode);
        $blindIndex = $this->encryption->blindIndex($normalized);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, address_normalized_blind_index) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, 'home', $blindIndex]);

        return $memberYearId;
    }

    public function testOnePersonSuggestsNormal(): void
    {
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');

        $estimate = $this->service->estimate('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertSame(1, $estimate->householdSize);
        $this->assertSame(HouseholdFeeCategory::NORMAL, $estimate->category);
    }

    public function testTwoPeopleSuggestCouple(): void
    {
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');
        $this->createMemberAtAddress('R. Station', '5', null, '1000');

        $estimate = $this->service->estimate('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertSame(2, $estimate->householdSize);
        $this->assertSame(HouseholdFeeCategory::COUPLE, $estimate->category);
    }

    public function testThreeOrMorePeopleSuggestFamily(): void
    {
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');

        $estimate = $this->service->estimate('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertSame(3, $estimate->householdSize);
        $this->assertSame(HouseholdFeeCategory::FAMILY, $estimate->category);
    }

    public function testAMemberMarkedLeavingIsExcludedFromTheCount(): void
    {
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000');
        $this->createMemberAtAddress('Rue de la Station', '5', null, '1000', leaving: true);

        $estimate = $this->service->estimate('Rue de la Station', '5', null, '1000', $this->scoutYearId);

        $this->assertSame(1, $estimate->householdSize);
        $this->assertSame(HouseholdFeeCategory::NORMAL, $estimate->category);
    }

    public function testHouseholdSizeMatchesTheRealCount(): void
    {
        $this->createMemberAtAddress('Avenue Louise', '10', 'A', '1050');
        $this->createMemberAtAddress('Avenue Louise', '10', 'A', '1050');
        $this->createMemberAtAddress('Avenue Louise', '10', 'B', '1050'); // different box → different household

        $estimate = $this->service->estimate('Avenue Louise', '10', 'A', '1050', $this->scoutYearId);

        $this->assertSame(2, $estimate->householdSize);
    }

    public function testNoMatchesGivesZeroAndNormal(): void
    {
        $estimate = $this->service->estimate('Rue Inconnue', '1', null, '9999', $this->scoutYearId);

        $this->assertSame(0, $estimate->householdSize);
        $this->assertSame(HouseholdFeeCategory::NORMAL, $estimate->category);
    }
}
