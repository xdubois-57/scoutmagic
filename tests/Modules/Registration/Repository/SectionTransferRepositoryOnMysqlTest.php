<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Database\ConstraintViolation;
use Modules\Registration\Repository\SectionTransferRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * `SectionTransferRepository::setDestination()` — the Passage page's
 * hand-written upsert — on the engine production runs (issue #481
 * part D).
 *
 * SELECT, then UPDATE or INSERT: spelled that way so SQLite runs it too,
 * and safe only because the UNIQUE index on (member, target year) makes
 * a second row impossible. The optimiser (IT-18) calls it once per child
 * inside one transaction and the page calls it once per pick, often with
 * the destination already stored — an UPDATE that changes no row on this
 * engine. `updated_at` is written from PHP, never left to the column's
 * `DEFAULT CURRENT_TIMESTAMP`. The table is the migration's, foreign keys
 * to `members`, `scout_years` and `sections` included.
 */
#[Group('database')]
class SectionTransferRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SectionTransferRepository $transfers;
    private int $yearId;
    private int $memberId;
    private int $firstSectionId;
    private int $secondSectionId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->transfers = new SectionTransferRepository($this->pdo);

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-CHILD');
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->firstSectionId = $this->insertSection($branchId, 'TROUPE-A');
        $this->secondSectionId = $this->insertSection($branchId, 'TROUPE-B');
    }

    /** The premise: one destination per member and target year, enforced by the engine. */
    public function testTheEngineRefusesASecondDestinationForTheSameMemberAndYear(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO registration_section_transfers (member_id, target_scout_year_id, destination_section_id, '
                . 'updated_at) VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$this->memberId, $this->yearId, $this->firstSectionId, '2026-09-01 10:00:00']);

        try {
            $insert->execute([$this->memberId, $this->yearId, $this->secondSectionId, '2026-09-01 10:00:00']);
            $this->fail('a second destination for the same member and year was accepted.');
        } catch (\PDOException $e) {
            $this->assertSame('1062', (string) ($e->errorInfo[1] ?? ''), $e->getMessage());
            $this->assertTrue(ConstraintViolation::isDuplicateKey($e), 'issue #592: the race fallback would not catch this');
        }
    }

    public function testSettingThenMovingADestinationKeepsOneRow(): void
    {
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->secondSectionId);

        $this->assertSame(
            $this->secondSectionId,
            $this->transfers->findDestinationSectionId($this->memberId, $this->yearId)
        );
        $this->assertSame(1, $this->countTransfers());
    }

    /**
     * The same pick twice in one second — the optimiser re-applying what
     * a chief already chose. The UPDATE changes nothing, MariaDB reports
     * no row, and nothing here may read that as « insert one ».
     */
    public function testTheSameDestinationTwiceInOneSecondKeepsOneRow(): void
    {
        $this->pdo->beginTransaction();
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);
        $this->pdo->commit();

        $this->assertSame(
            $this->firstSectionId,
            $this->transfers->findDestinationSectionId($this->memberId, $this->yearId)
        );
        $this->assertSame(1, $this->countTransfers());
    }

    /** The server's clock is moved hours away first, or the two would agree and the test could not tell. */
    public function testUpdatedAtIsPhpsClockOnInsertAndOnUpdate(): void
    {
        $this->assertGreaterThan(
            5 * 3600,
            $this->skewProductionEngineClock(),
            'the server clock could not be moved away from PHP\'s, so this test could not tell them apart'
        );
        $before = new \DateTimeImmutable('-1 second');
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);
        $this->assertStampedBetween($before, new \DateTimeImmutable('+1 second'));

        // Pushed into the past, so the UPDATE branch has something to move.
        $this->pdo->prepare('UPDATE registration_section_transfers SET updated_at = ? WHERE member_id = ?')
            ->execute(['2020-01-01 00:00:00', $this->memberId]);

        $before = new \DateTimeImmutable('-1 second');
        $this->transfers->setDestination($this->memberId, $this->yearId, $this->secondSectionId);
        $this->assertStampedBetween($before, new \DateTimeImmutable('+1 second'));
    }

    /**
     * « Réinitialiser » empties one target year, and only that one; a
     * single pick can be cleared back to « non défini ».
     */
    public function testClearingIsScopedToTheMemberOrTheYear(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $otherMemberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-OTHER');

        $this->transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);
        $this->transfers->setDestination($otherMemberId, $this->yearId, $this->secondSectionId);
        $this->transfers->setDestination($this->memberId, $otherYearId, $this->secondSectionId);

        $this->transfers->clearDestination($otherMemberId, $this->yearId);
        $this->assertSame(
            [$this->memberId => $this->firstSectionId],
            $this->transfers->findDestinationsForMembers([$this->memberId, $otherMemberId], $this->yearId)
        );

        $this->transfers->clearAllForYear($this->yearId);
        $this->assertSame([], $this->transfers->findDestinationsForMembers([$this->memberId], $this->yearId));
        $this->assertSame(
            $this->secondSectionId,
            $this->transfers->findDestinationSectionId($this->memberId, $otherYearId),
            'another target year is not the one being reset'
        );
    }

    private function assertStampedBetween(\DateTimeImmutable $before, \DateTimeImmutable $after): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT updated_at FROM registration_section_transfers WHERE member_id = ? AND target_scout_year_id = ?'
        );
        $stmt->execute([$this->memberId, $this->yearId]);
        $stamp = new \DateTimeImmutable((string) $stmt->fetchColumn());

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $stamp->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $stamp->getTimestamp());
    }

    private function insertSection(int $ageBranchId, string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$ageBranchId, $deskCode, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function countTransfers(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM registration_section_transfers');
    }

    /** A single value from a prepared statement: every statement here is prepared, even a fixed one. */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
