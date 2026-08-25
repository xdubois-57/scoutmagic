<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\FeeCategoryRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoicePerson;
use Modules\Fees\Invoice\ParsedInvoice;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\InvoiceRepository;
use Modules\Fees\Repository\RosterSnapshotRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\InvoiceVerificationService;
use Modules\Fees\Value\ReconstitutedLine;
use Modules\Fees\Value\StoredInvoice;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * "Did the federation count the right number of people?"
 *
 * The expected figure comes from the SNAPSHOT — what Desk held on the day
 * — and never from a tariff calculation. Confusing the two produces a
 * screen that accuses the federation of an error the unit made.
 *
 * A line the site cannot judge is `null`, never zero, and the two are not
 * the same claim.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceVerificationServiceTest extends TestCase
{
    private \PDO $pdo;
    private InvoiceRepository $invoices;
    private RosterSnapshotRepository $snapshots;
    private InvoiceVerificationService $service;
    private EncryptionService $encryption;
    private int $scoutYearId;
    private int $louveteauxId;
    private int $staffId;
    private int $normalFeeId;
    private int $familyFeeId;
    private int $functionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $encryption = $this->encryption;

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L1', 'Meute Akela')");
        $this->louveteauxId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'STAFFDU', 'Staff unite')");
        $this->staffId = (int) $this->pdo->lastInsertId();

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Animé', 'Animé', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $this->service = new InvoiceVerificationService(
            $this->invoices,
            $this->snapshots,
            new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories),
            new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)),
            new HouseholdDetailRepository($this->pdo, $encryption)
        );
    }

    private function createMemberYear(?int $feeCategoryId, ?int $sectionId, ?string $formationLevel = null): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       fee_category_id, formation_level, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc', $feeCategoryId, $formationLevel]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        if ($sectionId !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$memberYearId, $this->functionId, $sectionId]);
        }
    }

    /**
     * @param InvoiceLine[] $lines
     */
    private function storeInvoice(array $lines, ?int $snapshotId, string $issueDate = '2026-01-08'): StoredInvoice
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amountCents;
        }

        $id = $this->invoices->store(
            new ParsedInvoice('F2026/000123', $issueDate, $lines, $total, null, null, null, 0),
            $this->scoutYearId,
            $snapshotId,
            null,
            ['SV025L1' => $this->louveteauxId, 'STAFFDU' => $this->staffId],
            []
        );

        $invoice = $this->invoices->findById($id);
        $this->assertNotNull($invoice);

        return $invoice;
    }

    /** @param int $count how many names to invent under the line */
    private function people(int $count): array
    {
        $people = [];
        for ($i = 0; $i < $count; $i++) {
            $people[] = new InvoicePerson('Nom' . $i, 'Prenom' . $i, '2012-03-15', null);
        }

        return $people;
    }

    public function testALineTheSnapshotAgreesWithIsConforming(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 2, 7800, $this->people(2))],
            $snapshot->id
        );

        $lines = $this->service->reconstitutedLines($invoice);
        $this->assertCount(1, $lines);
        $this->assertSame(2, $lines[0]->expectedQuantity);
        $this->assertSame(0, $lines[0]->difference());
        $this->assertTrue($lines[0]->matches());
        $this->assertSame('Meute Akela', $lines[0]->sectionLabel);
        $this->assertSame(0, $this->service->countDiscrepancies($invoice));
    }

    /** The gap goes both ways and its sign is what says which. */
    public function testTheFederationBillingOneTooManyIsAPositiveGapCostedAtTheLinesOwnPrice(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 2, 7800, $this->people(2))],
            $snapshot->id
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertSame(1, $line->difference());
        $this->assertSame(3900, $line->differenceCents());
        $this->assertSame(1, $this->service->countDiscrepancies($invoice));
    }

    public function testTheFederationBillingOneTooFewIsANegativeGap(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 2, 7800, $this->people(2))],
            $snapshot->id
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertSame(-1, $line->difference());
        $this->assertSame(-3900, $line->differenceCents());
    }

    /** Each tariff is counted on its own: a famille member is not a normale one. */
    public function testEachTariffIsCountedSeparatelyWithinASection(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $this->createMemberYear($this->familyFeeId, $this->louveteauxId);
        $this->createMemberYear($this->familyFeeId, $this->louveteauxId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice([
            new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 1, 3900, $this->people(1)),
            new InvoiceLine('COT_FAM', 'Cotisation famille', 'SV025L1', 3100, 2, 6200, $this->people(2)),
        ], $snapshot->id);

        $lines = $this->service->reconstitutedLines($invoice);
        $this->assertSame(1, $lines[0]->expectedQuantity);
        $this->assertSame(2, $lines[1]->expectedQuantity);
        $this->assertSame(0, $this->service->countDiscrepancies($invoice));
    }

    /** Another section's members are not this line's. */
    public function testASectionsCountIgnoresTheOtherSections(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $this->createMemberYear($this->normalFeeId, $this->staffId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 1, 3900, $this->people(1))],
            $snapshot->id
        );

        $this->assertSame(1, $this->service->reconstitutedLines($invoice)[0]->expectedQuantity);
    }

    /**
     * The brevet reduction counts the people who HOLD one, not the people
     * on a tariff.
     */
    public function testABrevetReductionIsCountedAgainstTheFormationLevels(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->staffId, "Brevet d'animateur");
        $this->createMemberYear($this->normalFeeId, $this->staffId, 'Formation en cours');
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('RED_ANIM_BREV', 'Réduction animateur breveté', 'STAFFDU', -1000, 1, -1000, $this->people(1))],
            $snapshot->id
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertSame(1, $line->expectedQuantity);
        $this->assertTrue($line->matches());
    }

    /**
     * Undetermined, not zero: an unknown reference disables the only check
     * the line had, and the screen says so rather than accusing the
     * federation of billing for nobody.
     */
    public function testAnUnknownReferenceLeavesTheLineUndeterminedRatherThanAtZero(): void
    {
        $this->createMemberYear($this->normalFeeId, $this->louveteauxId);
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_iAM_LOCAL', 'Cotisation Iam locale', 'SV025L1', 2500, 1, 2500, $this->people(1))],
            $snapshot->id
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertFalse($line->isDetermined());
        $this->assertNull($line->expectedQuantity);
        $this->assertNull($line->difference());
        $this->assertSame(ReconstitutedLine::UNDETERMINED_UNKNOWN_REFERENCE, $line->undeterminedReason);
        $this->assertSame(0, $this->service->countDiscrepancies($invoice), 'Silence is not an accusation.');
    }

    public function testAGlobalAdjustmentIsNeverJudged(): void
    {
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));

        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_ACOMPTE', 'Acompte déjà versé', null, -30000, 1, -30000)],
            $snapshot->id
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertSame(ReconstitutedLine::UNDETERMINED_GLOBAL_ADJUSTMENT, $line->undeterminedReason);
    }

    public function testWithoutASnapshotEveryLineIsUndeterminedRatherThanWrong(): void
    {
        $invoice = $this->storeInvoice(
            [new InvoiceLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, 2, 7800, $this->people(2))],
            null
        );

        $line = $this->service->reconstitutedLines($invoice)[0];
        $this->assertSame(ReconstitutedLine::UNDETERMINED_NO_SNAPSHOT, $line->undeterminedReason);
        $this->assertSame(0, $this->service->countDiscrepancies($invoice));
    }

    /**
     * One day of drift is enough to produce differences that are not
     * differences, so the gap is shown rather than hidden.
     */
    public function testTheGapBetweenTheSnapshotAndTheInvoiceIsCounted(): void
    {
        $snapshot = $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'));
        $invoice = $this->storeInvoice([], $snapshot->id, '2026-01-08');

        $this->assertSame(-3, $this->service->snapshotDateGapInDays($invoice));
    }

    public function testWithoutASnapshotThereIsNoGapToShow(): void
    {
        $invoice = $this->storeInvoice([], null, '2026-01-08');

        $this->assertNull($this->service->snapshotDateGapInDays($invoice));
    }
}
