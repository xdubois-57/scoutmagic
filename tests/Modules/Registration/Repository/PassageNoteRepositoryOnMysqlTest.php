<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Core\Security\EncryptionService;
use Modules\Registration\Repository\PassageNoteRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * `PassageNoteRepository`'s insert-or-update, on the engine production
 * runs (issue #481 part D).
 *
 * Every write of the Passage page goes through `ensureRow()` — look the
 * (member, year) row up, INSERT it when absent — and then an UPDATE of
 * the one field being saved. It is an upsert written by hand so that it
 * reads the same on both engines, and what makes it safe is the UNIQUE
 * index on (member, year) plus four writers that each touch only their
 * own column. Both are checked here against the table the migration
 * builds, foreign keys to `members`, `sections` and `user_accounts`
 * included — the SQLite fixture the service tests use declares none.
 *
 * An UPDATE that writes what is already there changes no row on this
 * engine; none of these methods reads `rowCount()`, and the test that
 * saves the same note twice pins that it stays that way.
 */
#[Group('database')]
class PassageNoteRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private PassageNoteRepository $notes;
    private int $yearId;
    private int $memberId;
    private int $sectionId;
    private int $chiefAccountId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->notes = new PassageNoteRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
        $this->memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-CHILD');
        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->sectionId = $this->insertSection($branchId, 'MEUTE-A');
        $this->chiefAccountId = $this->insertUserAccount('chef');
    }

    /**
     * The premise the hand-written upsert rests on: this engine refuses a
     * second row for the same member and year. Without the index, two
     * requests racing through `ensureRow()` would leave two rows, and
     * `findForYear()` would pick one of them at random.
     */
    public function testTheEngineRefusesASecondRowForTheSameMemberAndYear(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO registration_passage_notes (member_id, scout_year_id, updated_at) VALUES (?, ?, ?)'
        );
        $insert->execute([$this->memberId, $this->yearId, '2026-09-01 10:00:00']);

        try {
            $insert->execute([$this->memberId, $this->yearId, '2026-09-01 10:00:00']);
            $this->fail('a second passage note for the same member and year was accepted.');
        } catch (\PDOException $e) {
            // 1062 = duplicate entry for a UNIQUE key.
            $this->assertSame('1062', (string) ($e->errorInfo[1] ?? ''), $e->getMessage());
        }
    }

    /**
     * The four writers share one row and each leaves the others' columns
     * alone — the page saves each field on its own as it is edited.
     */
    public function testEachWriterCreatesOrReusesOneRowAndTouchesOnlyItsOwnField(): void
    {
        $this->notes->setPreferredSection($this->memberId, $this->yearId, $this->sectionId, $this->chiefAccountId);
        $this->notes->setStaffNote($this->memberId, $this->yearId, ' Avec sa sœur ', $this->chiefAccountId);
        $this->notes->setAiSuggestion($this->memberId, $this->yearId, hash('sha256', 'commentaire'), 'Meute A');
        $this->notes->confirmAiSuggestion($this->memberId, $this->yearId, true);

        $this->assertSame(1, $this->countNotes(), 'four writes to one member and year are one row');

        $note = $this->notes->find($this->memberId, $this->yearId);
        $this->assertSame([
            'preferred_section_id' => $this->sectionId,
            'staff_note' => 'Avec sa sœur',
            'ai_source_hash' => hash('sha256', 'commentaire'),
            'ai_suggestion' => 'Meute A',
            'ai_confirmed' => true,
        ], $note);
    }

    /**
     * The same value written twice: the second UPDATE changes nothing and
     * MariaDB reports no row, which must neither raise nor add one.
     *
     * The repeated `confirmAiSuggestion(false)` is that unchanged write. A
     * repeated staff note is not: it is encrypted with a fresh IV, so its
     * stored bytes differ every time. The last assertion checks that the
     * confirm really leaves the row as it was, so the case is reached
     * rather than assumed.
     */
    public function testSavingTheSameValueTwiceKeepsOneRow(): void
    {
        $this->notes->setStaffNote($this->memberId, $this->yearId, 'Timide', $this->chiefAccountId);
        $this->notes->setStaffNote($this->memberId, $this->yearId, 'Timide', $this->chiefAccountId);
        $this->notes->confirmAiSuggestion($this->memberId, $this->yearId, false);
        $this->notes->confirmAiSuggestion($this->memberId, $this->yearId, false);

        $this->assertSame(1, $this->countNotes());
        $this->assertSame('Timide', $this->notes->find($this->memberId, $this->yearId)['staff_note'] ?? null);

        $unchanged = $this->pdo->prepare(
            'UPDATE registration_passage_notes SET ai_confirmed = 0 WHERE member_id = ? AND scout_year_id = ?'
        );
        $unchanged->execute([$this->memberId, $this->yearId]);
        $this->assertSame(0, $unchanged->rowCount(), 'the repeated confirm was not an unchanged write');
    }

    /** A re-read of an edited comment never inherits the previous validation. */
    public function testANewSuggestionArrivesUnconfirmed(): void
    {
        $this->notes->setAiSuggestion($this->memberId, $this->yearId, hash('sha256', 'v1'), 'Meute A');
        $this->notes->confirmAiSuggestion($this->memberId, $this->yearId, true);

        $this->notes->setAiSuggestion($this->memberId, $this->yearId, hash('sha256', 'v2'), 'Meute B');

        $note = $this->notes->find($this->memberId, $this->yearId);
        $this->assertNotNull($note);
        $this->assertFalse($note['ai_confirmed']);
        $this->assertSame(hash('sha256', 'v2'), $note['ai_source_hash']);
    }

    public function testClearingThePreferredSectionAndTheNoteStoresNull(): void
    {
        $this->notes->setPreferredSection($this->memberId, $this->yearId, $this->sectionId, null);
        $this->notes->setStaffNote($this->memberId, $this->yearId, 'Timide', null);

        $this->notes->setPreferredSection($this->memberId, $this->yearId, null, null);
        $this->notes->setStaffNote($this->memberId, $this->yearId, '   ', null);

        $note = $this->notes->find($this->memberId, $this->yearId);
        $this->assertNotNull($note);
        $this->assertNull($note['preferred_section_id']);
        $this->assertNull($note['staff_note']);
    }

    /**
     * `updated_at` has `DEFAULT CURRENT_TIMESTAMP`, the server's clock;
     * the repository writes PHP's on the INSERT and on every UPDATE that
     * stamps it, and records who saved.
     */
    public function testUpdatedAtIsPhpsClockAndTheActorIsRecorded(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $this->notes->setStaffNote($this->memberId, $this->yearId, 'Timide', $this->chiefAccountId);
        $after = new \DateTimeImmutable('+1 second');

        $stmt = $this->pdo->prepare(
            'SELECT updated_at, updated_by_user_account_id FROM registration_passage_notes
              WHERE member_id = ? AND scout_year_id = ?'
        );
        $stmt->execute([$this->memberId, $this->yearId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $updatedAt = new \DateTimeImmutable((string) $row['updated_at']);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $updatedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $updatedAt->getTimestamp());
        $this->assertSame($this->chiefAccountId, (int) $row['updated_by_user_account_id']);
    }

    public function testFindForYearIsKeyedByMemberAndLimitedToTheYear(): void
    {
        $otherYearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
        $otherMemberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK-OTHER');

        $this->notes->setStaffNote($this->memberId, $this->yearId, 'Cette année', null);
        $this->notes->setStaffNote($otherMemberId, $this->yearId, 'Un autre', null);
        $this->notes->setStaffNote($this->memberId, $otherYearId, 'L\'an prochain', null);

        $forYear = $this->notes->findForYear($this->yearId);

        $this->assertEqualsCanonicalizing([$this->memberId, $otherMemberId], array_keys($forYear));
        $this->assertSame('Cette année', $forYear[$this->memberId]['staff_note']);
    }

    private function insertSection(int $ageBranchId, string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$ageBranchId, $deskCode, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertUserAccount(string $who): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['opaque-' . $who, hash('sha256', $who)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function countNotes(): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM registration_passage_notes');
    }

    /** A single value from a prepared statement: every statement here is prepared, even a fixed one. */
    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
