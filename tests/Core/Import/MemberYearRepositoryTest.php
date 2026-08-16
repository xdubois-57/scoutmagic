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

    // --- scout_year_offset must survive a Desk import into a new year ---

    /**
     * @return array<string, mixed>
     */
    private function encryptedPayload(): array
    {
        return [
            'first_name_encrypted' => $this->encryption->encrypt('Test'),
            'last_name_encrypted' => $this->encryption->encrypt('User'),
            'gender_encrypted' => $this->encryption->encrypt('M'),
            'birth_date_encrypted' => $this->encryption->encrypt('2015-06-01'),
            'phone_encrypted' => null,
            'mobile_encrypted' => null,
            'email_encrypted' => null,
            'email_blind_index' => null,
            'totem_encrypted' => null,
            'quali_encrypted' => null,
            'patrol_encrypted' => null,
            'formation_level' => null,
            'federation_mail_consent' => false,
            'unit_mail_consent' => false,
            'fee_category_id' => null,
            'unit_code' => null,
            'handicap_encrypted' => null,
            'supplementary_insurance' => null,
        ];
    }

    private function offsetOf(int $memberYearId): int
    {
        return (int) $this->pdo->query('SELECT scout_year_offset FROM member_years WHERE id = ' . $memberYearId)->fetchColumn();
    }

    /**
     * scout_year_offset is ScoutMagic-local — Desk knows nothing about it,
     * so the import's INSERT never supplied it and every new scout year
     * silently reset an advanced/held-back member to 0, quietly moving
     * them into the wrong branch and year-in-branch everywhere effective
     * age is used (member page, member_stats, capacities, Prévisions).
     */
    public function testUpsertCarriesScoutYearOffsetForwardIntoANewScoutYear(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d100')");
        $memberId = (int) $this->pdo->lastInsertId();

        $firstYearId = $this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload());
        $this->repo->updateScoutYearOffset($firstYearId, 1);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $newRowId = $this->repo->upsert($memberId, $nextYearId, $this->encryptedPayload());

        $this->assertSame(1, $this->offsetOf($newRowId), 'the offset follows the member into the newly imported year');
    }

    public function testUpsertCarriesANegativeOffsetForwardToo(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d101')");
        $memberId = (int) $this->pdo->lastInsertId();

        $firstYearId = $this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload());
        $this->repo->updateScoutYearOffset($firstYearId, -1);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $this->assertSame(-1, $this->offsetOf($this->repo->upsert($memberId, $nextYearId, $this->encryptedPayload())));
    }

    /**
     * Only ever inherited on INSERT — re-importing a year the member
     * already has must leave a chief's correction on that row alone.
     */
    public function testReimportingTheSameYearNeverOverwritesAnExistingOffset(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d102')");
        $memberId = (int) $this->pdo->lastInsertId();

        $yearRowId = $this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload());
        $this->repo->updateScoutYearOffset($yearRowId, 1);

        $this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload());

        $this->assertSame(1, $this->offsetOf($yearRowId));
    }

    /**
     * Inheritance walks backwards by start_date, never by row id or scout
     * year id — back-filling an older year afterwards must not become the
     * source a *later* year inherits from.
     */
    public function testInheritanceIgnoresALaterYearAndPrefersTheMostRecentEarlierOne(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d103')");
        $memberId = (int) $this->pdo->lastInsertId();

        // A much older year, inserted first, with a different offset.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2023-2024', '2023-09-01', '2024-08-31', 0)");
        $oldestYearId = (int) $this->pdo->lastInsertId();
        $this->repo->updateScoutYearOffset($this->repo->upsert($memberId, $oldestYearId, $this->encryptedPayload()), -1);

        // The immediately-preceding year carries the offset that should win.
        $this->repo->updateScoutYearOffset($this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload()), 1);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        $nextYearId = (int) $this->pdo->lastInsertId();

        $this->assertSame(1, $this->offsetOf($this->repo->upsert($memberId, $nextYearId, $this->encryptedPayload())));
    }

    public function testAMemberWithNoEarlierYearSimplyStartsAtZero(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('d104')");
        $memberId = (int) $this->pdo->lastInsertId();

        $this->assertSame(0, $this->offsetOf($this->repo->upsert($memberId, $this->scoutYearId, $this->encryptedPayload())));
    }
}
