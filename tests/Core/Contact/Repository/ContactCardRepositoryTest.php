<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Repository;

use Core\Contact\Repository\ContactCardRepository;
use Core\Database\Connection;
use Core\Database\InstrumentedPdo;
use Core\Database\QueryCounter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The affiliation history and the revision instant — the two reads a
 * contact card needs and no existing repository answers.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactCardRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ContactCardRepository $repository;
    private int $memberId;
    /** @var array<string, int> label => scout_years.id */
    private array $years = [];
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase(new InstrumentedPdo('sqlite::memory:'));
        $this->repository = new ContactCardRepository(Connection::withPdo($this->pdo));

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (age_branch_id, desk_code, name) VALUES ($branchId, 'LOUV1', 'Les Loups Gris')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('TRES', 'Trésorier', 'admin')");

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        foreach (['2023-2024' => '2023-09-01', '2024-2025' => '2024-09-01', '2025-2026' => '2025-09-01'] as $label => $start) {
            $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
            $stmt->execute([$label, $start, substr($start, 0, 4) + 1 . '-08-31']);
            $this->years[$label] = (int) $this->pdo->lastInsertId();
        }
    }

    private function addMemberYear(string $yearLabel, string $createdAt = '2025-09-02 08:00:00'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->memberId, $this->years[$yearLabel], 'x', 'y', $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    private function addFunction(int $memberYearId, string $deskCode, ?int $sectionId, bool $isMain = false): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, (SELECT id FROM functions WHERE desk_code = ?), ?, ?)'
        );
        $stmt->execute([$memberYearId, $deskCode, $sectionId, $isMain ? 1 : 0]);
    }

    public function testTheHistoryIsMostRecentFirstAndCoversEveryYear(): void
    {
        $this->addFunction($this->addMemberYear('2023-2024'), 'ANIM', $this->sectionId);
        $this->addFunction($this->addMemberYear('2024-2025'), 'TRES', null);
        $this->addFunction($this->addMemberYear('2025-2026'), 'ANIM', $this->sectionId, true);

        $affiliations = $this->repository->findAffiliationsForMember($this->memberId);

        $this->assertSame(
            [
                '2025-2026 · Animateur · Les Loups Gris',
                '2024-2025 · Trésorier',
                '2023-2024 · Animateur · Les Loups Gris',
            ],
            array_map(static fn($a): string => $a->format(), $affiliations)
        );
    }

    /**
     * The history is read from `member_years` for one `members.id`, in ONE
     * statement — never one per scout year, which is what rebuilding it
     * out of MemberProfile would have cost.
     */
    public function testTheWholeHistoryCostsOneStatement(): void
    {
        foreach (['2023-2024', '2024-2025', '2025-2026'] as $label) {
            $this->addFunction($this->addMemberYear($label), 'ANIM', $this->sectionId);
        }

        QueryCounter::reset();
        $this->repository->findAffiliationsForMember($this->memberId);

        $this->assertSame(1, QueryCounter::count());
    }

    /**
     * A section renamed in Configuration reads under its new name for
     * every year it appears in — the name is resolved through the section
     * row, never copied into the history.
     */
    public function testARenamedSectionReadsUnderItsNewNameForPastYearsToo(): void
    {
        $this->addFunction($this->addMemberYear('2023-2024'), 'ANIM', $this->sectionId);

        $stmt = $this->pdo->prepare('UPDATE sections SET name = ? WHERE id = ?');
        $stmt->execute(['Les Loups Blancs', $this->sectionId]);

        $this->assertSame(
            '2023-2024 · Animateur · Les Loups Blancs',
            $this->repository->findAffiliationsForMember($this->memberId)[0]->format()
        );
    }

    /**
     * A section whose name was never configured is named NOT AT ALL —
     * `sections.desk_code` is never a fallback, because a Desk code has no
     * business appearing in somebody's address book.
     */
    public function testASectionWithoutANameIsSimplyNotNamed(): void
    {
        $this->pdo->exec("UPDATE sections SET name = NULL WHERE id = {$this->sectionId}");
        $this->addFunction($this->addMemberYear('2025-2026'), 'ANIM', $this->sectionId);

        $line = $this->repository->findAffiliationsForMember($this->memberId)[0]->format();

        $this->assertSame('2025-2026 · Animateur', $line);
        $this->assertStringNotContainsString('LOUV1', $line);
    }

    public function testSeveralFunctionsOfOneYearLandOnOneLine(): void
    {
        $memberYearId = $this->addMemberYear('2025-2026');
        $this->addFunction($memberYearId, 'ANIM', $this->sectionId, true);
        $this->addFunction($memberYearId, 'TRES', null);

        $this->assertSame(
            ['2025-2026 · Animateur · Les Loups Gris ; Trésorier'],
            array_map(
                static fn($a): string => $a->format(),
                $this->repository->findAffiliationsForMember($this->memberId)
            )
        );
    }

    /**
     * A Desk export carries one row per (function × address), so the same
     * function can be stored twice for one year — the same trap
     * Core\Member\MemberFunctionInfo::deduplicate() exists for.
     */
    public function testTheSameFunctionStoredTwiceIsReadOnce(): void
    {
        $memberYearId = $this->addMemberYear('2025-2026');
        $this->addFunction($memberYearId, 'ANIM', $this->sectionId, true);
        $this->addFunction($memberYearId, 'ANIM', $this->sectionId, true);

        $this->assertSame(
            ['2025-2026 · Animateur · Les Loups Gris'],
            array_map(
                static fn($a): string => $a->format(),
                $this->repository->findAffiliationsForMember($this->memberId)
            )
        );
    }

    /**
     * A member can have a `member_years` row and no function at all —
     * `Core\Attention\CoreAttentionRepository` exists to flag exactly
     * that, a Desk encoding somebody never finished. The year belongs in
     * the history all the same: dropping it would make a card skip a
     * season without saying so.
     */
    public function testAYearWithNoFunctionAtAllStillAppearsInTheHistory(): void
    {
        $this->addMemberYear('2024-2025');
        $this->addFunction($this->addMemberYear('2025-2026'), 'ANIM', $this->sectionId, true);

        $this->assertSame(
            ['2025-2026 · Animateur · Les Loups Gris', '2024-2025'],
            array_map(
                static fn($a): string => $a->format(),
                $this->repository->findAffiliationsForMember($this->memberId)
            )
        );
    }

    public function testAMemberWhoseOnlyYearHasNoFunctionStillHasThatYear(): void
    {
        $this->addMemberYear('2025-2026');

        $affiliations = $this->repository->findAffiliationsForMember($this->memberId);

        $this->assertCount(1, $affiliations);
        $this->assertSame([], $affiliations[0]->functions);
        $this->assertSame('2025-2026', $affiliations[0]->format());
    }

    public function testAMemberWithNoYearAtAllHasNoHistory(): void
    {
        $this->assertSame([], $this->repository->findAffiliationsForMember($this->memberId));
        $this->assertSame([], $this->repository->findAffiliationsForMembers([]));
    }

    public function testARevisionIsTheLatestEventThatCouldHaveMovedTheCard(): void
    {
        $this->addMemberYear('2025-2026', '2025-09-02 08:00:00');

        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) VALUES (?, 0, 0, ?)'
        );
        $stmt->execute([$this->years['2025-2026'], '2026-03-04 10:11:12']);

        $this->assertSame(
            '2026-03-04 10:11:12',
            $this->repository->findRevisionsForMembers([$this->memberId])[$this->memberId]
        );
    }

    /**
     * A newly confirmed secondary address changes what the card's EMAIL
     * lines say, so it has to move the revision — otherwise a client keeps
     * a card that is missing an address forever.
     */
    public function testConfirmingASecondaryAddressMovesTheRevision(): void
    {
        $this->addMemberYear('2025-2026', '2025-09-02 08:00:00');

        $stmt = $this->pdo->prepare(
            "INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status, confirmed_at, created_at)
             VALUES (?, 'x', 'y', 'manual', 'valid', ?, ?)"
        );
        $stmt->execute([$this->memberId, '2026-05-06 07:08:09', '2025-10-01 00:00:00']);

        $this->assertSame(
            '2026-05-06 07:08:09',
            $this->repository->findRevisionsForMembers([$this->memberId])[$this->memberId]
        );
    }

    /**
     * The revision never decrypts a column — that is what lets IT-03's
     * `getctag` answer a client that polls every few minutes.
     */
    public function testTheRevisionOfAWholeAddressBookCostsOneStatement(): void
    {
        $this->addMemberYear('2025-2026');
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-2')");
        $second = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$second, $this->years['2025-2026'], 'a', 'b']);

        QueryCounter::reset();
        $revisions = $this->repository->findRevisionsForMembers([$this->memberId, $second]);

        $this->assertSame(1, QueryCounter::count());
        $this->assertCount(2, $revisions);
    }

    public function testAMemberNothingIsKnownAboutHasNoRevision(): void
    {
        $this->assertSame([], $this->repository->findRevisionsForMembers([$this->memberId]));
        $this->assertSame([], $this->repository->findRevisionsForMembers([]));
    }
}
