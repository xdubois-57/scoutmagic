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
use Core\Import\RosterSnapshotRepository;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\InvoiceVerificationService;
use Modules\Fees\Value\NominativeDiscrepancy;
use Modules\Fees\Value\StoredInvoice;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * « Écarts nominatifs » — the half of the report that says WHO.
 *
 * The count check says a section is one over; this says which person, and
 * which of five different things is going on. They are five and not one
 * because each names a different thing to go and do, and a report that
 * merged them would tell a treasurer that "something is wrong with
 * Baptiste" and stop there.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InvoiceNominativeDiscrepancyTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private InvoiceRepository $invoices;
    private RosterSnapshotRepository $snapshots;
    private InvoiceVerificationService $service;
    private int $scoutYearId;
    private int $louveteauxId;
    private int $staffId;
    private int $eclaireursId;
    private int $normalFeeId;
    private int $familyFeeId;
    private int $animateurFeeId;
    private int $functionId;

    /** @var array<string, int> match key => members.id, for the store */
    private array $matches = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOUV', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025L1', 'Meute Akela')");
        $this->louveteauxId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'SV025E1', 'Troupe Sanglier')");
        $this->eclaireursId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branchId}, 'STAFFDU', 'Staff U')");
        $this->staffId = (int) $this->pdo->lastInsertId();

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');
        $this->animateurFeeId = $feeCategories->create('Tarif animateur', 'Tarif animateur');

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Anime', 'Anime', 'identified')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->snapshots = new RosterSnapshotRepository($this->pdo);
        $this->service = new InvoiceVerificationService(
            $this->invoices,
            $this->snapshots,
            new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories),
            new SectionService(Connection::withPdo($this->pdo), $this->encryption, new MemberBadgeRepository($this->pdo)),
            new HouseholdDetailRepository($this->pdo, $this->encryption)
        );
    }

    /**
     * A member of the year, and the person an invoice would name them by.
     *
     * @return array{int, InvoicePerson} members.id, and the invoice's own name for them
     */
    private function member(
        string $firstName,
        string $lastName,
        ?int $feeCategoryId,
        ?int $sectionId,
        bool $leaving = false,
        ?string $formationLevel = null
    ): array {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       birth_date_encrypted, fee_category_id, formation_level, leaving, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $this->encryption->encrypt('2012-03-15', 'member_years.birth_date'),
            $feeCategoryId,
            $formationLevel,
            $leaving ? 1 : 0,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        if ($sectionId !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
            );
            $stmt->execute([$memberYearId, $this->functionId, $sectionId]);
        }

        $person = new InvoicePerson($lastName, $firstName, '2012-03-15', null);
        $this->matches[$person->matchKey()] = $memberId;

        return [$memberId, $person];
    }

    private function snapshot(): int
    {
        return $this->snapshots->capture($this->scoutYearId, new \DateTimeImmutable('2026-01-05 07:00:00'))->id;
    }

    /** @param InvoiceLine[] $lines */
    private function storeInvoice(array $lines, ?int $snapshotId): StoredInvoice
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += $line->amountCents;
        }

        $id = $this->invoices->store(
            new ParsedInvoice('F2026/000123', '2026-01-08', $lines, $total, null, null, null, 0),
            $this->scoutYearId,
            $snapshotId,
            null,
            ['SV025L1' => $this->louveteauxId, 'SV025E1' => $this->eclaireursId, 'STAFFDU' => $this->staffId],
            $this->matches
        );

        $invoice = $this->invoices->findById($id);
        $this->assertNotNull($invoice);

        return $invoice;
    }

    /** @param InvoicePerson[] $people */
    private function feeLine(string $reference, string $descriptor, ?string $sectionCode, int $unitPrice, array $people): InvoiceLine
    {
        return new InvoiceLine(
            $reference, $descriptor, $sectionCode, $unitPrice, count($people), $unitPrice * count($people), $people
        );
    }

    /** @return string[] */
    private function kinds(StoredInvoice $invoice): array
    {
        return array_map(
            static fn(NominativeDiscrepancy $d): string => $d->kind,
            $this->service->nominativeDiscrepancies($invoice)
        );
    }

    // --- the five kinds -------------------------------------------------

    public function testEverythingAgreeingProducesNoDiscrepancyAtAll(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $this->assertSame([], $this->service->nominativeDiscrepancies($invoice));
    }

    /**
     * The federation is not wrong: Desk still holds them. What is out of
     * date is Desk, and the money is real until it is corrected there.
     */
    public function testSomeoneBilledWhomDeskMarksLeavingIsNamedAndCosted(): void
    {
        [$memberId, $camille] = $this->member('Camille', 'Renard', $this->normalFeeId, $this->louveteauxId, leaving: true);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$camille])],
            $snapshotId
        );

        $discrepancies = $this->service->nominativeDiscrepancies($invoice);
        $this->assertCount(1, $discrepancies);
        $this->assertSame(NominativeDiscrepancy::BILLED_BUT_LEAVING, $discrepancies[0]->kind);
        $this->assertSame($memberId, $discrepancies[0]->memberId);
        $this->assertSame('Camille', $discrepancies[0]->firstName);
        $this->assertSame('Renard', $discrepancies[0]->lastName);
        $this->assertSame(3900, $discrepancies[0]->costCents);
    }

    public function testSomeoneDeskHoldsAndTheInvoiceOmitsIsNamedWithANegativeIncidence(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->louveteauxId);
        [$absentId] = $this->member('Zoé', 'Pissoort', $this->normalFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $discrepancies = $this->service->nominativeDiscrepancies($invoice);
        $this->assertCount(1, $discrepancies);
        $this->assertSame(NominativeDiscrepancy::NOT_ON_INVOICE, $discrepancies[0]->kind);
        $this->assertSame($absentId, $discrepancies[0]->memberId);
        // Under-billed: the unit will owe it in the regularisation.
        $this->assertSame(-3900, $discrepancies[0]->costCents);
    }

    /**
     * An invoice covering two sections out of three is not "missing" the
     * third — reporting its whole roster as absent would bury the one real
     * omission under a hundred false ones.
     */
    public function testASectionTheInvoiceNeverBillsIsNotReportedAsAbsent(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->louveteauxId);
        $this->member('Louise', 'Marchal', $this->normalFeeId, $this->eclaireursId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $this->assertSame([], $this->kinds($invoice));
    }

    /** A tariff outside the three is not something the site can judge. */
    public function testAMemberOnATariffTheSiteDoesNotRecogniseIsNotReportedAsAbsent(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->louveteauxId);
        $this->member('Sophie', 'Delvaux', $this->animateurFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $this->assertSame([], $this->kinds($invoice));
    }

    /**
     * The tariff is the same either way, so the report says it and does
     * not put a euro figure on it.
     */
    public function testASectionDiscrepancyIsSignalledWithoutBeingCosted(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->eclaireursId);
        $snapshotId = $this->snapshot();

        // Billed under the Louveteaux, held by Desk in the Troupe.
        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $discrepancies = $this->service->nominativeDiscrepancies($invoice);
        $sectionOnes = array_values(array_filter(
            $discrepancies,
            static fn(NominativeDiscrepancy $d): bool => $d->kind === NominativeDiscrepancy::DIFFERENT_SECTION
        ));

        $this->assertCount(1, $sectionOnes);
        $this->assertNull($sectionOnes[0]->costCents);
        $this->assertTrue($sectionOnes[0]->costsNothingByNature());
        $this->assertSame('Meute Akela', $sectionOnes[0]->billedSectionLabel);
        $this->assertSame('Troupe Sanglier', $sectionOnes[0]->rosterSectionLabel);
    }

    public function testACategoryDiscrepancyCostsTheDifferenceReadOffTheInvoiceItself(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->familyFeeId, $this->louveteauxId);
        [, $louise] = $this->member('Louise', 'Marchal', $this->familyFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        // Basile is billed at the normal tariff; Desk has him on famille,
        // and the document's own famille line says what that costs.
        $invoice = $this->storeInvoice([
            $this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile]),
            $this->feeLine('COT_FAM', 'Cotisation famille', 'SV025L1', 3100, [$louise]),
        ], $snapshotId);

        $discrepancies = array_values(array_filter(
            $this->service->nominativeDiscrepancies($invoice),
            static fn(NominativeDiscrepancy $d): bool => $d->kind === NominativeDiscrepancy::DIFFERENT_CATEGORY
        ));

        $this->assertCount(1, $discrepancies);
        $this->assertSame('Normal', $discrepancies[0]->billedCategoryLabel);
        $this->assertSame('Famille', $discrepancies[0]->rosterCategoryLabel);
        $this->assertSame(800, $discrepancies[0]->costCents, '39,00 billed where the document prices famille at 31,00.');
    }

    /**
     * Without a price for the expected tariff anywhere on the document,
     * the difference is real but unquantifiable — and it is shown without
     * a figure rather than with a wrong one.
     */
    public function testACategoryDiscrepancyThisDocumentCannotPriceIsShownWithoutAFigure(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->familyFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile])],
            $snapshotId
        );

        $discrepancies = array_values(array_filter(
            $this->service->nominativeDiscrepancies($invoice),
            static fn(NominativeDiscrepancy $d): bool => $d->kind === NominativeDiscrepancy::DIFFERENT_CATEGORY
        ));

        $this->assertCount(1, $discrepancies);
        $this->assertNull($discrepancies[0]->costCents);
        $this->assertFalse($discrepancies[0]->costsNothingByNature(), 'Unquantified is not the same claim as costs nothing.');
    }

    public function testABrevetedAnimatorTheReductionSkippedIsNamedAndCosted(): void
    {
        [, $sophie] = $this->member('Sophie', 'Delvaux', $this->normalFeeId, $this->staffId, formationLevel: "Brevet d'animateur");
        [$oublieId] = $this->member('Marc', 'Colin', $this->normalFeeId, $this->staffId, formationLevel: 'Breveté fédéral');
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice([
            $this->feeLine('COT_NORM', 'Cotisation normale', 'STAFFDU', 3900, [$sophie]),
            new InvoiceLine('RED_ANIM_BREV', 'Réduction animateur breveté', 'STAFFDU', -1000, 1, -1000, [$sophie]),
        ], $snapshotId);

        $discrepancies = array_values(array_filter(
            $this->service->nominativeDiscrepancies($invoice),
            static fn(NominativeDiscrepancy $d): bool => $d->kind === NominativeDiscrepancy::BREVET_REDUCTION_MISSING
        ));

        $this->assertCount(1, $discrepancies);
        $this->assertSame($oublieId, $discrepancies[0]->memberId);
        $this->assertSame(1000, $discrepancies[0]->costCents, 'The unit paid the reduction it should have had.');
    }

    /**
     * A document carrying no brevet line at all is not a document that
     * forgot one — the federation may bill the reduction separately, or
     * this may be a deposit. A page of false alarms is how a report stops
     * being read.
     */
    public function testADocumentWithNoBrevetLineAtAllFlagsNobody(): void
    {
        [, $sophie] = $this->member('Sophie', 'Delvaux', $this->normalFeeId, $this->staffId, formationLevel: "Brevet d'animateur");
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'STAFFDU', 3900, [$sophie])],
            $snapshotId
        );

        $this->assertSame([], $this->kinds($invoice));
    }

    // --- edges ----------------------------------------------------------

    public function testWithoutASnapshotThereIsNothingToCompareAndNothingIsClaimed(): void
    {
        [, $camille] = $this->member('Camille', 'Renard', $this->normalFeeId, $this->louveteauxId, leaving: true);

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$camille])],
            null
        );

        $this->assertSame([], $this->service->nominativeDiscrepancies($invoice));
    }

    /**
     * The site could not tie the name to anybody, so there is no
     * nominative row to write — but the number has to reach the report,
     * because a verification of 40 people that quietly checked 34 is worse
     * than no verification.
     */
    public function testPeopleTheSiteNeverMatchedAreCountedRatherThanNamed(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->normalFeeId, $this->louveteauxId);
        $stranger = new InvoicePerson('Inconnu', 'Personne', '2011-01-01', null);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile, $stranger])],
            $snapshotId
        );

        $this->assertSame([], $this->kinds($invoice));
        $this->assertSame(1, $this->service->unmatchedPeopleCount($invoice));
    }

    /** Two different things about one person are two rows, not one verdict. */
    public function testOnePersonCanCarryTwoDifferentDiscrepancies(): void
    {
        [, $basile] = $this->member('Basile', 'Dubois', $this->familyFeeId, $this->eclaireursId);
        [, $louise] = $this->member('Louise', 'Marchal', $this->familyFeeId, $this->eclaireursId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice([
            $this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$basile]),
            $this->feeLine('COT_FAM', 'Cotisation famille', 'SV025E1', 3100, [$louise]),
        ], $snapshotId);

        $kinds = $this->kinds($invoice);
        $this->assertContains(NominativeDiscrepancy::DIFFERENT_SECTION, $kinds);
        $this->assertContains(NominativeDiscrepancy::DIFFERENT_CATEGORY, $kinds);
    }

    /** Grouped by kind, then by name — the order the export writes them in. */
    public function testTheReportComesBackGroupedByKindThenByName(): void
    {
        [, $camille] = $this->member('Camille', 'Renard', $this->normalFeeId, $this->louveteauxId, leaving: true);
        [, $anne] = $this->member('Anne', 'Bastin', $this->normalFeeId, $this->louveteauxId, leaving: true);
        $this->member('Zoé', 'Pissoort', $this->normalFeeId, $this->louveteauxId);
        $snapshotId = $this->snapshot();

        $invoice = $this->storeInvoice(
            [$this->feeLine('COT_NORM', 'Cotisation normale', 'SV025L1', 3900, [$camille, $anne])],
            $snapshotId
        );

        $rows = $this->service->nominativeDiscrepancies($invoice);
        $this->assertSame(
            [
                [NominativeDiscrepancy::BILLED_BUT_LEAVING, 'Bastin'],
                [NominativeDiscrepancy::BILLED_BUT_LEAVING, 'Renard'],
                [NominativeDiscrepancy::NOT_ON_INVOICE, 'Pissoort'],
            ],
            array_map(static fn(NominativeDiscrepancy $d): array => [$d->kind, $d->lastName], $rows)
        );
    }
}
