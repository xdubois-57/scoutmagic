<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\AddressBlindIndexBackfill;
use Core\Member\AddressNormalizer;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * TEMPORARY test for TEMPORARY code — remove alongside Core\Member\
 * AddressBlindIndexBackfill at iteration 3.
 *
 * @group database
 */
class AddressBlindIndexBackfillTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AddressBlindIndexBackfill $backfill;
    private int $memberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->backfill = new AddressBlindIndexBackfill($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, 'enc', 'enc']);
        $this->memberYearId = (int) $this->pdo->lastInsertId();
    }

    private function insertAddressWithoutIndex(string $street, string $number, string $postalCode): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted, postal_code_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberYearId,
            'home',
            $this->encryption->encrypt($street),
            $this->encryption->encrypt($number),
            $this->encryption->encrypt($postalCode),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testFillsRowsMissingTheIndex(): void
    {
        $id = $this->insertAddressWithoutIndex('Rue de la Station', '5', '1000');

        $updated = $this->backfill->run();

        $this->assertSame(1, $updated);
        $blindIndex = $this->pdo->query('SELECT address_normalized_blind_index FROM member_addresses WHERE id = ' . $id)->fetchColumn();
        $expected = $this->encryption->blindIndex(AddressNormalizer::normalize('Rue de la Station', '5', null, '1000'));
        $this->assertSame($expected, $blindIndex);
    }

    public function testDoesNotTouchRowsThatAlreadyHaveAnIndex(): void
    {
        $id = $this->insertAddressWithoutIndex('Rue de la Station', '5', '1000');
        $existingValue = 'already-set-value';
        $this->pdo->prepare('UPDATE member_addresses SET address_normalized_blind_index = ? WHERE id = ?')
            ->execute([$existingValue, $id]);

        $updated = $this->backfill->run();

        $this->assertSame(0, $updated);
        $blindIndex = $this->pdo->query('SELECT address_normalized_blind_index FROM member_addresses WHERE id = ' . $id)->fetchColumn();
        $this->assertSame($existingValue, $blindIndex);
    }

    public function testIsANoOpOnceEveryRowIsAlreadyIndexed(): void
    {
        $this->insertAddressWithoutIndex('Rue de la Station', '5', '1000');
        $this->backfill->run();

        $secondRun = $this->backfill->run();

        $this->assertSame(0, $secondRun);
    }
}
