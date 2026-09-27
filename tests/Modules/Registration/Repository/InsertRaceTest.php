<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The three SELECT-then-INSERT writes of the module, when they lose the
 * race to a concurrent request (issue #592).
 *
 * Two saves on the same child both find no row, both insert, and the
 * unique index on (member, year) refuses the second. A test cannot slot
 * a second request between two statements, so the connection here plays
 * the loser: its first existence check reads nothing, while the winner's
 * row is already in the table — exactly what the losing request saw. The
 * INSERT that follows then meets the index, as it would in production.
 *
 * Each case must end with the later write stored and no exception: the
 * guardian, the chief, sees their choice saved rather than an error page.
 */
#[Group('database')]
final class InsertRaceTest extends TestCase
{
    private RaceLosingPdo $pdo;
    private EncryptionService $encryption;
    private int $yearId;
    private int $memberId;
    private int $firstSectionId;
    private int $secondSectionId;

    protected function setUp(): void
    {
        $this->pdo = new RaceLosingPdo('sqlite::memory:');
        DatabaseTestHelper::createTestDatabase($this->pdo);
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-CHILD');
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'ECLA', 'Éclaireurs', 30);
        $this->firstSectionId = $this->insertSection($branchId, 'TROUPE-A');
        $this->secondSectionId = $this->insertSection($branchId, 'TROUPE-B');
    }

    public function testAPassageNoteStillSavesWhenAnotherRequestCreatedTheRowFirst(): void
    {
        $notes = new PassageNoteRepository($this->pdo, $this->encryption);
        $notes->setPreferredSection($this->memberId, $this->yearId, $this->firstSectionId, null);

        $this->pdo->loseTheNextRaceOn('registration_passage_notes');
        $notes->setPreferredSection($this->memberId, $this->yearId, $this->secondSectionId, null);

        $this->assertTrue($this->pdo->raceWasLost(), 'the existence check was not the one this test blinds');
        $this->assertSame($this->secondSectionId, $notes->find($this->memberId, $this->yearId)['preferred_section_id']);
        $this->assertSame(1, $this->rowCount('registration_passage_notes'));
    }

    public function testASectionTransferKeepsTheLaterPickWhenTwoCross(): void
    {
        $transfers = new SectionTransferRepository($this->pdo);
        $transfers->setDestination($this->memberId, $this->yearId, $this->firstSectionId);

        $this->pdo->loseTheNextRaceOn('registration_section_transfers');
        $transfers->setDestination($this->memberId, $this->yearId, $this->secondSectionId);

        $this->assertTrue($this->pdo->raceWasLost(), 'the existence check was not the one this test blinds');
        $this->assertSame(
            $this->secondSectionId,
            $transfers->findDestinationSectionId($this->memberId, $this->yearId)
        );
        $this->assertSame(1, $this->rowCount('registration_section_transfers'));
    }

    public function testAReenrollmentAnswerReplacesTheOneItCrossed(): void
    {
        $answers = new ReenrollmentRepository($this->pdo, $this->encryption);
        $firstId = $answers->saveAnswer($this->memberId, $this->yearId, 'staying', null, null, null, [
            ['raw_name' => 'Alice', 'matched_member_id' => null, 'match_state' => 'unmatched'],
        ]);

        $this->pdo->loseTheNextRaceOn('registration_reenrollments');
        $secondId = $answers->saveAnswer($this->memberId, $this->yearId, 'leaving', null, 'Déménagement', null, []);

        $this->assertTrue($this->pdo->raceWasLost(), 'the existence check was not the one this test blinds');
        $this->assertSame($firstId, $secondId, 'the answer it crossed was replaced, not duplicated');
        $answer = $answers->findAnswer($this->memberId, $this->yearId);
        $this->assertNotNull($answer);
        $this->assertSame('leaving', $answer->decision);
        $this->assertSame(1, $this->rowCount('registration_reenrollments'));
        $this->assertSame(0, $this->rowCount('registration_friend_wishes'), 'the first answer\'s wishes went with it');
    }

    /**
     * Only the unique index is absorbed: a row the schema refuses for any
     * other reason — here a member that does not exist — still fails.
     */
    public function testAnInsertRefusedForAnotherReasonIsStillThrown(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $transfers = new SectionTransferRepository($this->pdo);

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');
        $transfers->setDestination(999999, $this->yearId, $this->firstSectionId);
    }

    private function insertSection(int $ageBranchId, string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$ageBranchId, $deskCode, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}

/**
 * A connection whose next existence check on one table reads nothing:
 * the view of the request that ran its SELECT a moment before the
 * winner's INSERT committed.
 */
final class RaceLosingPdo extends \PDO
{
    private ?string $blindTable = null;
    private bool $lost = false;

    public function loseTheNextRaceOn(string $table): void
    {
        $this->blindTable = $table;
        $this->lost = false;
    }

    public function raceWasLost(): bool
    {
        return $this->lost;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->blindTable !== null
            && preg_match('/^\s*SELECT id FROM ' . preg_quote($this->blindTable, '/') . '\s+WHERE/i', $query) === 1
        ) {
            $this->blindTable = null;
            $this->lost = true;
            $query .= ' AND 1 = 0';
        }

        return parent::prepare($query, $options);
    }
}
