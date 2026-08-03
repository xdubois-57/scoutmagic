<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\MemberYearRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberYearRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private MemberYearRepository $repo;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repo = new MemberYearRepository($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->repo->findById(999));
    }

    public function testFindAllByEmailReturnsMatchingRows(): void
    {
        $email = 'test@example.com';
        $blindIndex = $this->encryption->blindIndex($email);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d001')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Test'),
            $this->encryption->encrypt('User'),
            $this->encryption->encrypt($email),
            $blindIndex,
        ]);

        $results = $this->repo->findAllByEmail($blindIndex, $this->scoutYearId);
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('desk_id', $results[0]);
    }

    public function testFindAllByEmailReturnsEmptyForNoMatch(): void
    {
        $results = $this->repo->findAllByEmail('nonexistent', $this->scoutYearId);
        $this->assertEmpty($results);
    }

    public function testFindMostRecentEmailBlindIndexForMemberReturnsNullWhenNoYearHasOne(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d002')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)')
            ->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Test'), $this->encryption->encrypt('User')]);

        $this->assertNull($this->repo->findMostRecentEmailBlindIndexForMember($memberId));
    }

    public function testFindMostRecentEmailBlindIndexForMemberPrefersTheLatestScoutYear(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d003')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $olderYearId = (int) $this->pdo->lastInsertId();

        $oldBlindIndex = $this->encryption->blindIndex('old@example.com');
        $newBlindIndex = $this->encryption->blindIndex('new@example.com');

        $insert = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$memberId, $olderYearId, $this->encryption->encrypt('Test'), $this->encryption->encrypt('User'), $oldBlindIndex]);
        $insert->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Test'), $this->encryption->encrypt('User'), $newBlindIndex]);

        $this->assertSame($newBlindIndex, $this->repo->findMostRecentEmailBlindIndexForMember($memberId));
    }

    public function testFindMostRecentEmailBlindIndexForMemberSkipsAYearWithNoEmailToFindAnOlderOne(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d004')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $olderYearId = (int) $this->pdo->lastInsertId();

        $oldBlindIndex = $this->encryption->blindIndex('old@example.com');

        $insert = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$memberId, $olderYearId, $this->encryption->encrypt('Test'), $this->encryption->encrypt('User'), $oldBlindIndex]);
        // Current year has no email at all.
        $insert->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Test'), $this->encryption->encrypt('User'), null]);

        $this->assertSame($oldBlindIndex, $this->repo->findMostRecentEmailBlindIndexForMember($memberId));
    }
}
