<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Repository;

use Core\Member\AddressNormalizer;
use Core\Security\EncryptionService;
use Modules\Fees\Repository\HouseholdDetailRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The module's one encrypted read: names and one readable address per
 * household, decrypted here and nowhere else (SECURITY.md §5).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HouseholdDetailRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private HouseholdDetailRepository $repository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new HouseholdDetailRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    /** @return int member_year id */
    private function createMember(string $first, string $last, ?string $totem = null, bool $leaving = false): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       totem_encrypted, leaving, leaving_marked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($first, 'member_years.first_name'),
            $this->encryption->encrypt($last, 'member_years.last_name'),
            $totem === null ? null : $this->encryption->encrypt($totem, 'member_years.totem'),
            $leaving ? 1 : 0,
            $leaving ? '2026-01-06 10:00:00' : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addAddress(int $memberYearId, string $street, string $number, string $postalCode, string $city): string
    {
        $normalized = AddressNormalizer::normalize($street, $number, null, $postalCode);
        $blindIndex = $this->encryption->blindIndex($normalized, 'address');

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
            $this->encryption->encrypt($postalCode, 'member_addresses.postal_code'),
            $this->encryption->encrypt($city, 'member_addresses.city'),
            $blindIndex,
        ]);

        return $blindIndex;
    }

    public function testItDecryptsTheNamesAndReportsTheDepartureAsItStands(): void
    {
        $staying = $this->createMember('Jean', 'DUPONT', 'Baloo');
        $going = $this->createMember('Camille', 'DUPONT', null, leaving: true);

        $rows = $this->repository->findMembers([$staying, $going]);

        $this->assertSame('Jean', $rows[$staying]['first_name']);
        $this->assertSame('DUPONT', $rows[$staying]['last_name']);
        $this->assertSame('Baloo', $rows[$staying]['totem']);
        $this->assertFalse($rows[$staying]['leaving']);

        $this->assertNull($rows[$going]['totem']);
        $this->assertTrue($rows[$going]['leaving']);
        $this->assertSame('2026-01-06 10:00:00', $rows[$going]['leaving_marked_at']);
    }

    public function testAnEmptyListRunsNoQuery(): void
    {
        $this->assertSame([], $this->repository->findMembers([]));
        $this->assertSame([], $this->repository->findAddressLabels([]));
    }

    public function testItReadsOneReadableAddressPerHousehold(): void
    {
        $first = $this->createMember('Jean', 'Dupont');
        $second = $this->createMember('Marie', 'Dupont');
        $blindIndex = $this->addAddress($first, 'Rue de la Station', '5', '1000', 'Bruxelles');
        $this->addAddress($second, 'R. Station', '5', '1000', 'Bruxelles');

        $labels = $this->repository->findAddressLabels([$blindIndex]);

        $this->assertCount(1, $labels, 'Both rows share one blind index, so they are one household.');
        $this->assertStringContainsString('5', $labels[$blindIndex]);
        $this->assertStringContainsString('1000 Bruxelles', $labels[$blindIndex]);
    }

    /**
     * A house number typed into the street field is normal in Desk data;
     * the address reads the same here as it does on the member page
     * (ARCHITECTURE.md §8.34's display-side normalisation).
     */
    public function testAHouseNumberTypedIntoTheStreetFieldStillReadsAsAnAddress(): void
    {
        $memberYearId = $this->createMember('Jean', 'Dupont');
        $blindIndex = $this->addAddress($memberYearId, '12 Rue de la Paix', '', '1000', 'Bruxelles');

        $label = $this->repository->findAddressLabels([$blindIndex])[$blindIndex];

        $this->assertStringContainsString('Rue de la Paix', $label);
        $this->assertStringStartsNotWith('12', $label);
    }

    /**
     * A row this key cannot open must not take the whole screen down with
     * it: the card is drawn with what could be read.
     */
    public function testARowThisKeyCannotOpenLeavesAnEmptyFieldRatherThanThrowing(): void
    {
        $memberYearId = $this->createMember('Jean', 'Dupont');
        $this->pdo->exec("UPDATE member_years SET first_name_encrypted = 'not-a-ciphertext' WHERE id = {$memberYearId}");

        $rows = $this->repository->findMembers([$memberYearId]);

        $this->assertSame('', $rows[$memberYearId]['first_name']);
        $this->assertSame('Dupont', $rows[$memberYearId]['last_name']);
    }

    public function testAnAddressThatDecryptsToNothingIsNamedRatherThanLeftBlank(): void
    {
        $memberYearId = $this->createMember('Jean', 'Dupont');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, address_normalized_blind_index) VALUES (?, ?, ?)'
        );
        $stmt->execute([$memberYearId, 'Domicile', 'an-index-with-no-readable-address']);

        $labels = $this->repository->findAddressLabels(['an-index-with-no-readable-address']);

        $this->assertSame('Adresse inconnue', $labels['an-index-with-no-readable-address']);
    }
}
