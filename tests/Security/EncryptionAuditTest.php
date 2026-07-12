<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class EncryptionAuditTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(
            str_repeat('a', 32),
            str_repeat('b', 32)
        );
    }

    public function testMemberYearPersonalDataIsNotPlaintext(): void
    {
        // Create scout year and member
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('test_enc_001')");
        $memberId = (int) $this->pdo->lastInsertId();

        $plainFirstName = 'Jean-Pierre';
        $plainLastName = 'Dupont';
        $plainEmail = 'jean@example.com';
        $plainPhone = '+32477123456';
        $plainGender = 'M';
        $plainBirthDate = '1990-01-15';

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
             email_encrypted, email_blind_index, phone_encrypted, gender_encrypted, birth_date_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $this->encryption->encrypt($plainFirstName),
            $this->encryption->encrypt($plainLastName),
            $this->encryption->encrypt($plainEmail),
            $this->encryption->blindIndex($plainEmail),
            $this->encryption->encrypt($plainPhone),
            $this->encryption->encrypt($plainGender),
            $this->encryption->encrypt($plainBirthDate),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        // Query raw table and verify data is NOT plaintext
        $stmt = $this->pdo->prepare('SELECT * FROM member_years WHERE id = ?');
        $stmt->execute([$memberYearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertNotSame($plainFirstName, $row['first_name_encrypted']);
        $this->assertNotSame($plainLastName, $row['last_name_encrypted']);
        $this->assertNotSame($plainEmail, $row['email_encrypted']);
        $this->assertNotSame($plainPhone, $row['phone_encrypted']);
        $this->assertNotSame($plainGender, $row['gender_encrypted']);
        $this->assertNotSame($plainBirthDate, $row['birth_date_encrypted']);

        // Verify blind index is NOT the plaintext email
        $this->assertNotSame($plainEmail, $row['email_blind_index']);

        // Verify decryption works correctly
        $this->assertSame($plainFirstName, $this->encryption->decrypt($row['first_name_encrypted']));
        $this->assertSame($plainLastName, $this->encryption->decrypt($row['last_name_encrypted']));
        $this->assertSame($plainEmail, $this->encryption->decrypt($row['email_encrypted']));
    }

    public function testMemberAddressDataIsNotPlaintext(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('test_enc_002')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $scoutYearId,
            $this->encryption->encrypt('Test'),
            $this->encryption->encrypt('User'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $plainStreet = 'Rue de la Liberté';
        $plainCity = 'Bruxelles';
        $plainPostal = '1000';

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, city_encrypted, postal_code_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId, 'Domicile',
            $this->encryption->encrypt($plainStreet),
            $this->encryption->encrypt($plainCity),
            $this->encryption->encrypt($plainPostal),
        ]);
        $addrId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT * FROM member_addresses WHERE id = ?');
        $stmt->execute([$addrId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertNotSame($plainStreet, $row['street_encrypted']);
        $this->assertNotSame($plainCity, $row['city_encrypted']);
        $this->assertNotSame($plainPostal, $row['postal_code_encrypted']);
    }

    public function testUserAccountEmailIsNotPlaintext(): void
    {
        $plainEmail = 'admin@example.com';

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 0)'
        );
        $stmt->execute([
            $this->encryption->encrypt($plainEmail),
            $this->encryption->blindIndex($plainEmail),
        ]);
        $userId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT * FROM user_accounts WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertNotSame($plainEmail, $row['email_encrypted']);
        $this->assertNotSame($plainEmail, $row['email_blind_index']);
    }
}
