<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\ImportDiff;
use Core\Import\ImportQuality;
use Core\Import\ImportRecord;
use Core\Import\ImportReportPresenter;
use Core\Import\ImportReportRepository;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The import report: what the stored diff becomes on screen.
 *
 * The presenter resolves ids into names and never recomputes a figure —
 * that is the whole reason the page can still be honest in June about an
 * import that ran in September.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportReportPresenterTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ImportReportPresenter $presenter;
    private int $scoutYearId;
    private int $sectionA;
    private int $sectionB;
    private int $newFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->presenter = new ImportReportPresenter(new ImportReportRepository($this->pdo, $this->encryption));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->sectionA = $this->createSection('BAL1', 'Baladins 1');
        $this->sectionB = $this->createSection('LOUV1', 'Louveteaux 1');
        $this->newFunctionId = $this->createFunction('EQUIPIER-ADJ', "Équipier d'unité adjoint", false);
    }

    public function testItNamesThePeopleTheDiffOnlyIdentifiesById(): void
    {
        $gone = $this->createMember('T001', 'Mommens', 'Pascale', null);
        $arrived = $this->createMember('T002', 'Roskam', 'Timéo', 'Baloo');

        $report = $this->presenter->present(
            $this->importRecord(),
            new ImportDiff(
                available: true,
                unavailableReason: null,
                previousImportId: 4,
                arrivedMemberIds: [$arrived],
                departedMemberIds: [$gone],
                adminLostMemberIds: [$gone]
            )
        );

        $this->assertTrue($report['available']);
        $this->assertSame('Pascale', $report['access']['admin_lost'][0]['identity']['first_name']);
        $this->assertSame('Mommens', $report['access']['admin_lost'][0]['identity']['last_name']);
        $this->assertSame('Baloo', $report['structure']['arrived'][0]['identity']['totem']);
    }

    public function testAMemberWhoseYearRowIsGoneStillCounts(): void
    {
        $report = $this->presenter->present(
            $this->importRecord(),
            new ImportDiff(true, null, 4, departedMemberIds: [999])
        );

        $this->assertCount(1, $report['structure']['departed']);
        $this->assertNull($report['structure']['departed'][0]['identity']);
        $this->assertSame(1, $report['counts']['departed']);
    }

    public function testSectionChangesReadAsLabelsNotIds(): void
    {
        $memberId = $this->createMember('T003', 'Meunier', 'Sacha', null);

        $report = $this->presenter->present(
            $this->importRecord(),
            new ImportDiff(true, null, 4, sectionChanges: [$memberId => ['from' => $this->sectionA, 'to' => $this->sectionB]])
        );

        $change = $report['structure']['section_changes'][0];
        $this->assertSame('Baladins 1', $change['from']);
        $this->assertSame('Louveteaux 1', $change['to']);
    }

    public function testANewFunctionIsPresentedAsSomethingToQualify(): void
    {
        $report = $this->presenter->present(
            $this->importRecord(),
            new ImportDiff(true, null, 4, newFunctionIds: [$this->newFunctionId])
        );

        $function = $report['access']['new_functions'][0];
        $this->assertSame("Équipier d'unité adjoint", $function['label']);
        $this->assertTrue($function['still_unconfirmed']);
        $this->assertSame(1, $report['counts']['access']);
    }

    public function testAFunctionQualifiedSinceTheImportNoLongerAsksForIt(): void
    {
        // The one thing on this page allowed to be current: "to qualify"
        // is a call to action, and a report months old must not keep
        // demanding something somebody already did.
        $stmt = $this->pdo->prepare('UPDATE functions SET confirmed = 1, role = ? WHERE id = ?');
        $stmt->execute(['chief', $this->newFunctionId]);

        $report = $this->presenter->present(
            $this->importRecord(),
            new ImportDiff(true, null, 4, newFunctionIds: [$this->newFunctionId])
        );

        $this->assertFalse($report['access']['new_functions'][0]['still_unconfirmed']);
    }

    public function testAnUnavailableDiffStillCarriesItsQualityCounters(): void
    {
        $diff = ImportDiff::unavailable(
            ImportDiff::UNAVAILABLE_FIRST_OF_SEASON,
            new ImportQuality(withoutUsableAddress: 3, withoutEmail: 1)
        );

        $report = $this->presenter->present($this->importRecord(), $diff);

        $this->assertFalse($report['available']);
        $this->assertSame(ImportDiff::UNAVAILABLE_FIRST_OF_SEASON, $report['unavailable_reason']);
        $this->assertSame(3, $report['quality'][0]['value']);
        $this->assertSame(1, $report['quality'][1]['value']);
    }

    public function testTheEmptyDiffOfAReimportIsSaidToBeEmpty(): void
    {
        $report = $this->presenter->present($this->importRecord(), new ImportDiff(true, null, 4));

        $this->assertTrue($report['is_empty']);
        $this->assertSame(0, $report['counts']['arrived']);
    }

    public function testTheQualityCountersAlwaysAppearEvenAtZero(): void
    {
        // A zero is an answer: "0 members without an e-mail address" is
        // worth reading, and a list that hid it would leave the reader
        // wondering whether it was checked.
        $report = $this->presenter->present($this->importRecord(), new ImportDiff(true, null, 4));

        $this->assertCount(4, $report['quality']);
        foreach ($report['quality'] as $row) {
            $this->assertSame(0, $row['value']);
        }
    }

    /* ------------------------------------------------------------------ */

    private function importRecord(): ImportRecord
    {
        return new ImportRecord(5, $this->scoutYearId, 1, 268, 262, 2, null, new \DateTimeImmutable('2026-02-09 21:40:00'));
    }

    private function createMember(string $deskId, string $lastName, string $firstName, ?string $totem): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute([$deskId]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $totem !== null ? $this->encryption->encrypt($totem, 'member_years.totem') : null,
        ]);

        return $memberId;
    }

    private function createSection(string $deskCode, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label) VALUES (?, ?)');
        $stmt->execute(['B-' . $deskCode, $deskCode]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createFunction(string $deskCode, string $label, bool $confirmed): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role, confirmed) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $label, 'identified', $confirmed ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }
}
