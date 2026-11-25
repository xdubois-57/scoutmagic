<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Config\ScoutYearService;
use Core\Import\RosterSnapshotRepository;
use Core\Import\FeeCategoryRepository;
use Modules\Fees\FeeTariff;
use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Member\HouseholdFeeCategory;
use Core\Security\EncryptionService;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The screen's whole judgement: what Desk holds today against what the
 * household's size implies, kept apart from what a known movement will
 * change later.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeeAccuracyServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private FeeAccuracyService $service;
    private HouseholdTariffService $tariffs;
    private IgnoredHouseholdRepository $ignored;
    private HouseholdService $households;
    private int $scoutYearId;
    private int $normalFeeId;
    private int $coupleFeeId;
    private int $familyFeeId;
    private int $unrecognisedFeeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', "
            . "'2026-08-31')"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->coupleFeeId = $feeCategories->create('N_C_COTISATION COUPLE', 'Cotisation couple');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');
        $this->unrecognisedFeeId = $feeCategories->create('ZX777_AUTRE', 'Autre cotisation');

        $this->households = new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption));
        $this->tariffs = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories);
        $this->ignored = new IgnoredHouseholdRepository($this->pdo, $this->encryption);
        $this->service = new FeeAccuracyService(
            $this->households,
            new HouseholdDetailRepository($this->pdo, $this->encryption),
            $this->tariffs,
            $this->ignored,
            $feeCategories,
            new RosterSnapshotRepository($this->pdo),
            FeesTestHelper::configuration($this->pdo, $this->tariffs, $feeCategories, '2025-2026'),
            new ScoutYearService($this->pdo)
        );
    }

    /** @return int member_year id */
    private function createMember(
        string $firstName,
        string $lastName,
        ?int $feeCategoryId,
        string $street = 'Rue de la Station',
        string $number = '5',
        ?string $postalCode = '1000',
        bool $leaving = false,
        bool $withAddress = true,
        ?string $startDate = '2025-09-01'
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       fee_category_id, leaving, leaving_marked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt($lastName, 'member_years.last_name'),
            $feeCategoryId,
            $leaving ? 1 : 0,
            $leaving ? '2026-01-06 10:00:00' : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (id, desk_code, label, role) VALUES (777, 'ZX777_FN', "
            . "'Fonction de test', 'identified')"
        );
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, start_date) VALUES (?, ?, ?)')
            ->execute([$memberYearId, 777, $startDate]);

        if ($withAddress) {
            $normalized = AddressNormalizer::normalize($street, $number, null, $postalCode);
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted,
                                               postal_code_encrypted, city_encrypted, address_normalized_blind_index)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberYearId,
                'Domicile',
                $this->encryption->encrypt($street, 'member_addresses.street'),
                $this->encryption->encrypt($number, 'member_addresses.number'),
                $this->encryption->encrypt((string) $postalCode, 'member_addresses.postal_code'),
                $this->encryption->encrypt('Bruxelles', 'member_addresses.city'),
                $normalized === '' ? null : $this->encryption->blindIndex($normalized, 'address'),
            ]);
        }

        return $memberYearId;
    }

    private function blindIndexFor(string $street, string $number, ?string $postalCode): string
    {
        return $this->encryption->blindIndex(
            AddressNormalizer::normalize($street, $number, null, $postalCode),
            'address'
        );
    }

    public function testAHouseholdOfThreeEncodedNormalNeedsCorrecting(): void
    {
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Marie', 'Dupont', $this->normalFeeId);
        $this->createMember('Léa', 'Dupont', $this->normalFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertCount(1, $report->toCorrect);
        $review = $report->toCorrect[0];
        $this->assertSame(3, $review->deskSize);
        $this->assertSame(HouseholdFeeCategory::FAMILY, $review->expectedCategory);
        $this->assertCount(3, $review->mismatchedMembers());
        $this->assertStringContainsString('Rue de la Station', $review->addressLabel);
    }

    public function testAHouseholdAlreadyOnTheRightTariffIsInNoTab(): void
    {
        $this->createMember('Jean', 'Dupont', $this->coupleFeeId);
        $this->createMember('Marie', 'Dupont', $this->coupleFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect);
        $this->assertSame([], $report->upcoming);
        $this->assertSame(1, $report->householdCount);
    }

    /**
     * The correction this iteration exists for: Desk still holds the member
     * whose departure was announced, and the federation still bills them.
     */
    public function testAHouseholdWithADepartureAnnouncedIsNotInBreachTodayButIsListedAsUpcoming(): void
    {
        $this->createMember('Jean', 'Dupont', $this->familyFeeId);
        $this->createMember('Marie', 'Dupont', $this->familyFeeId);
        $this->createMember('Camille', 'Dupont', $this->familyFeeId, leaving: true);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect, 'Desk holds three people and they are all on the family tariff.');
        $this->assertCount(1, $report->upcoming);
        $upcoming = $report->upcoming[0];
        $this->assertSame(HouseholdFeeCategory::FAMILY, $upcoming->expectedCategory);
        $this->assertSame(HouseholdFeeCategory::COUPLE, $upcoming->projectedCategory);
        $this->assertCount(1, $upcoming->leavingMembers());
        $this->assertSame('Camille', $upcoming->leavingMembers()[0]->firstName);
        $this->assertSame('2026-01-06 10:00:00', $upcoming->leavingMembers()[0]->leavingMarkedAt);
    }

    public function testAHouseholdCanBeBothToCorrectAndUpcoming(): void
    {
        // Encoded normal, three people in Desk (so wrong today), one of whom
        // is leaving (so it will move again afterwards).
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Marie', 'Dupont', $this->normalFeeId);
        $this->createMember('Camille', 'Dupont', $this->normalFeeId, leaving: true);

        $report = $this->service->report($this->scoutYearId);

        $this->assertCount(1, $report->toCorrect);
        $this->assertCount(1, $report->upcoming);
        $this->assertSame($report->toCorrect[0]->addressBlindIndex, $report->upcoming[0]->addressBlindIndex);
        $this->assertTrue($report->upcoming[0]->needsCorrection);
    }

    /**
     * Four people becoming three is famille either way — offering that as
     * something to act on would be inventing work.
     */
    public function testACountThatChangesWithoutTheCategoryMovingIsNotUpcoming(): void
    {
        $this->createMember('A', 'Dupont', $this->familyFeeId);
        $this->createMember('B', 'Dupont', $this->familyFeeId);
        $this->createMember('C', 'Dupont', $this->familyFeeId);
        $this->createMember('D', 'Dupont', $this->familyFeeId, leaving: true);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->upcoming);
        $this->assertSame([], $report->toCorrect);
    }

    /**
     * An unrecognised category is not a household tariff. Its holder is counted in
     * the household — the federation counts people — but never reported as
     * being on the wrong one.
     */
    public function testAMemberOnATariffOutsideTheThreeIsCountedButNeverJudged(): void
    {
        $this->createMember('Jean', 'Dupont', $this->familyFeeId);
        $this->createMember('Marie', 'Dupont', $this->familyFeeId);
        $this->createMember('Sophie', 'Dupont', $this->unrecognisedFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect);
        $households = $this->households->householdsForYear($this->scoutYearId);
        $this->assertSame(3, reset($households)->deskSize());
    }

    public function testAMemberWithNoFeeCategoryAtAllIsNotComparableEither(): void
    {
        $this->createMember('Jean', 'Dupont', null);
        $this->createMember('Marie', 'Dupont', $this->coupleFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect);
        $this->assertSame(1, $report->householdCount);
        $households = $this->households->householdsForYear($this->scoutYearId);
        $this->assertSame(2, reset($households)->deskSize());
    }

    public function testMembersWithNoUsableAddressAreCountedSeparatelyAndNeverJudged(): void
    {
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Sans', 'Adresse', $this->normalFeeId, withAddress: false);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame(1, $report->withoutAddressCount());
        $this->assertSame(1, $report->householdCount);
    }

    public function testAnIgnoredHouseholdLeavesTheOtherTabsAndCarriesItsReason(): void
    {
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Marie', 'Dupont', $this->normalFeeId);
        $this->createMember('Léa', 'Dupont', $this->normalFeeId);

        $blindIndex = $this->blindIndexFor('Rue de la Station', '5', '1000');
        $household = $this->households->householdsForYear($this->scoutYearId)[$blindIndex];
        $this->ignored->ignore(
            $this->scoutYearId,
            $blindIndex,
            FeeAccuracyService::compositionHash($household),
            'Garde alternée',
            null
        );

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect);
        $this->assertCount(1, $report->ignored);
        $this->assertSame('Garde alternée', $report->ignored[0]->ignored?->reason);
    }

    /**
     * A decision taken about three people does not cover a fourth: the
     * household comes back rather than staying silently excluded.
     */
    public function testAnIgnoredHouseholdComesBackWhenItsCompositionChanges(): void
    {
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Marie', 'Dupont', $this->normalFeeId);
        $this->createMember('Léa', 'Dupont', $this->normalFeeId);

        $blindIndex = $this->blindIndexFor('Rue de la Station', '5', '1000');
        $household = $this->households->householdsForYear($this->scoutYearId)[$blindIndex];
        $this->ignored->ignore($this->scoutYearId, $blindIndex, FeeAccuracyService::compositionHash($household), 'Colocation', null);

        $this->createMember('Nouveau', 'Venu', $this->normalFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertCount(1, $report->toCorrect);
        $this->assertSame([], $report->ignored);
    }

    public function testWithNoBaremeADiscrepancyIsShownWithoutAFigure(): void
    {
        $this->createMember('Jean', 'Dupont', $this->normalFeeId);
        $this->createMember('Marie', 'Dupont', $this->normalFeeId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertCount(1, $report->toCorrect);
        $this->assertNull($report->toCorrect[0]->differenceCents);
        $this->assertNull($report->toCorrectDifferenceCents());
    }

    /**
     * The sign is the whole point: under-declaring comes back in the
     * regularisation invoice, over-declaring does not.
     */
    public function testTheDiscrepancyIsSignedInBothDirections(): void
    {
        $this->tariffs->save(HouseholdFeeCategory::NORMAL, null, 4000);
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, null, 3500);
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3000);

        // Two people encoded on the family (cheaper) tariff: the unit is
        // declaring less than it owes.
        $this->createMember('Jean', 'Dupont', $this->familyFeeId);
        $this->createMember('Marie', 'Dupont', $this->familyFeeId);
        // Three people encoded normal (dearer) at another address: it is
        // declaring more than it owes.
        $this->createMember('A', 'Martin', $this->normalFeeId, 'Avenue Louise', '10', '1050');
        $this->createMember('B', 'Martin', $this->normalFeeId, 'Avenue Louise', '10', '1050');
        $this->createMember('C', 'Martin', $this->normalFeeId, 'Avenue Louise', '10', '1050');

        $report = $this->service->report($this->scoutYearId);

        $byAddress = [];
        foreach ($report->toCorrect as $review) {
            $byAddress[$review->expectedCategory->value] = $review->differenceCents;
        }

        // Couple expected (3500) against family encoded (3000), twice.
        $this->assertSame(1000, $byAddress['couple']);
        // Family expected (3000) against normal encoded (4000), three times.
        $this->assertSame(-3000, $byAddress['family']);
        $this->assertSame(-2000, $report->toCorrectDifferenceCents());
    }

    public function testAnExplicitTariffMappingOverridesTheHeuristic(): void
    {
        // This unit calls its family tariff something the classifier cannot
        // recognise, and says so on the barème panel.
        $oddId = (new FeeCategoryRepository($this->pdo))->create('T3PLUS', 'T3+');
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, $oddId, null);

        $this->createMember('Jean', 'Dupont', $oddId);
        $this->createMember('Marie', 'Dupont', $oddId);
        $this->createMember('Léa', 'Dupont', $oddId);

        $report = $this->service->report($this->scoutYearId);

        $this->assertSame([], $report->toCorrect, 'T3+ now means "famille" here, and three people is famille.');
    }
    private function importPreviousYear(): void
    {
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', "
            . "'2025-08-31')"
        );
        $previousId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) '
            . 'VALUES (?, 0, 0, ?)'
        )
            ->execute([$previousId, '2025-08-01 00:00:00']);
    }

    public function testGuestsOnOrdinaryDeskCodesDoNotInflateTheFamilyThresholdOrRequireAGuestCode(): void
    {
        $this->importPreviousYear();
        $this->createMember('A', 'Test', $this->normalFeeId);
        $this->createMember('B', 'Test', $this->normalFeeId, startDate: '2026-06-01');
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([[], []], [$report->toCorrect, $report->indeterminate]);
    }

    public function testAnIamaMemberStillMovesTheOtherMemberToTheCoupleTariff(): void
    {
        $iama = (new FeeCategoryRepository($this->pdo))->create('L_L_COTISATION IAMA', 'Cotisation IAMA');
        $this->createMember('A', 'Test', $this->normalFeeId);
        $this->createMember('B', 'Test', $iama);
        $review = $this->service->report($this->scoutYearId)->toCorrect[0];
        self::assertSame([FeeTariff::NORMAL], array_column($review->mismatchedMembers(), 'encodedCategory'));
        self::assertSame([FeeTariff::COUPLE, FeeTariff::IAMA], array_column($review->members, 'expectedCategory'));
    }

    public function testAnUnprovenGuestMakesACoupleOrFamilyThresholdIndeterminate(): void
    {
        $this->createMember('A', 'Test', $this->coupleFeeId);
        $this->createMember('B', 'Test', $this->coupleFeeId);
        $this->createMember('C', 'Test', $this->normalFeeId, startDate: null);
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([], $report->toCorrect);
        $review = $report->indeterminate[0];
        self::assertSame([2, 3, null, false], [
            $review->retainedCount->minimum, $review->retainedCount->maximum,
            $review->expectedCategory, $review->projectionIndeterminate,
        ]);
    }

    public function testThreeKnownMembersKeepTheFamilyThresholdCertainDespiteAnUnknownFourth(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->createMember($name, 'Test', $this->normalFeeId);
        }
        $this->createMember('D', 'Test', $this->normalFeeId, startDate: null);
        $report = $this->service->report($this->scoutYearId);
        self::assertCount(3, $report->toCorrect[0]->mismatchedMembers());
        self::assertCount(1, $report->indeterminate);
    }

    public function testADeskGuestCodeOutsideThePeriodIsACorrection(): void
    {
        $guest = (new FeeCategoryRepository($this->pdo))->create('ZX777_INVITES', 'Cotisation invités');
        $this->createMember('A', 'Test', $guest);
        $report = $this->service->report($this->scoutYearId);
        self::assertCount(1, $report->toCorrect);
        self::assertSame(FeeTariff::NORMAL, $report->toCorrect[0]->members[0]->expectedCategory);
    }

    public function testLeavingGuestsDoNotProduceAFictitiousUpcomingHouseholdTariff(): void
    {
        $this->importPreviousYear();
        $this->createMember('A', 'Test', $this->normalFeeId);
        $this->createMember('B', 'Test', $this->normalFeeId, leaving: true, startDate: '2026-08-31');
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([[], []], [$report->toCorrect, $report->upcoming]);
    }

    public function testAnIamaDepartureChangesOnlyTheRemainingOrdinaryMembersCost(): void
    {
        $this->tariffs->save(FeeTariff::NORMAL, null, 4000);
        $this->tariffs->save(FeeTariff::COUPLE, null, 3500);
        $this->tariffs->save(FeeTariff::FAMILY, null, 3000);
        $this->tariffs->save(FeeTariff::IAMA, null, 2500);
        $iama = (new FeeCategoryRepository($this->pdo))->create('L_L_COTISATION IAMA', 'Cotisation IAMA');
        $this->createMember('A', 'Test', $this->familyFeeId);
        $this->createMember('B', 'Test', $iama);
        $this->createMember('C', 'Test', $iama, leaving: true);
        self::assertSame(500, $this->service->report($this->scoutYearId)->upcoming[0]->projectedDifferenceCents);
    }

    public function testAnAllGuestHouseholdHasNoOrdinaryTariffToCorrect(): void
    {
        $this->importPreviousYear();
        $this->createMember('A', 'Test', $this->normalFeeId, startDate: '2026-06-01');
        $this->createMember('B', 'Test', $this->normalFeeId, startDate: '2026-08-31');
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([[], [], []], [$report->toCorrect, $report->upcoming, $report->indeterminate]);
    }

    public function testOnlyIamaMembersRemainingHaveNoUpcomingHouseholdCorrection(): void
    {
        $iama = (new FeeCategoryRepository($this->pdo))->create('L_L_COTISATION IAMA', 'Cotisation IAMA');
        $this->createMember('A', 'Test', $iama);
        $this->createMember('B', 'Test', $iama);
        $this->createMember('C', 'Test', $this->familyFeeId, leaving: true);
        self::assertSame([], $this->service->report($this->scoutYearId)->upcoming);
    }

    public function testOneUnpricedCorrectionDoesNotProduceAPartialTotal(): void
    {
        $this->tariffs->save(FeeTariff::NORMAL, null, 4000);
        $this->tariffs->save(FeeTariff::COUPLE, null, 3500);
        $this->createMember('A', 'Test', $this->normalFeeId);
        $this->createMember('B', 'Test', $this->normalFeeId);
        $this->createMember('C', 'Other', $this->normalFeeId, 'Rue de test', '8');
        $this->createMember('D', 'Other', $this->familyFeeId, 'Rue de test', '8');
        $report = $this->service->report($this->scoutYearId);
        $byMember = [];
        foreach ($report->toCorrect as $review) {
            $byMember[$review->members[0]->firstName] = $review->differenceCents;
        }
        ksort($byMember);
        self::assertSame([['A' => -1000, 'C' => null], null], [$byMember, $report->toCorrectDifferenceCents()]);
    }

    public function testAnUnrecognisedTariffIsCountedButReportedAsIndeterminate(): void
    {
        $this->createMember('A', 'Test', $this->coupleFeeId);
        $this->createMember('B', 'Test', $this->unrecognisedFeeId);
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([[], 2, 'Tarif Desk absent ou non reconnu. Vérifiez sa correspondance dans le barème.'], [
            $report->toCorrect, $report->indeterminate[0]->retainedCount->minimum,
            $report->indeterminate[0]->members[1]->reasonLabel(),
        ]);
    }

    public function testThePublicSummaryCarriesTheReportsIncompleteChecks(): void
    {
        $this->createMember('A', 'Test', $this->normalFeeId, startDate: null);
        $summaryProvider = new \Modules\Fees\Service\FeeAccuracySummaryService($this->service);
        $summary = $summaryProvider->summaryForYear($this->scoutYearId);
        self::assertSame([0, 1, 1], [
            $summary->householdsToCorrect, $summary->householdsIndeterminate, $summary->householdsChecked,
        ]);
    }

    public function testIndeterminateChecksRaiseAnAttentionPointWithoutInventingAnEncodingError(): void
    {
        $this->createMember('A', 'Test', $this->normalFeeId, startDate: null);
        $points = (new \Modules\Fees\Service\FeesAttentionProvider($this->service))->collect($this->scoutYearId);
        self::assertCount(1, $points);
        self::assertSame('1 foyer avec des contrôles non déterminables', $points[0]->title);
    }

    private function serviceWithIncomingRegistrations(int $incoming): FeeAccuracyService
    {
        $key = $this->blindIndexFor('Rue de la Station', '5', '1000');
        $provider = $this->createStub(\Modules\Registration\Api\HouseholdRegistrationCountProvider::class);
        $provider->method('countsInHouseholds')->willReturn([$key => $incoming]);
        $categories = new FeeCategoryRepository($this->pdo);

        return new FeeAccuracyService(
            new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption), $provider),
            new HouseholdDetailRepository($this->pdo, $this->encryption),
            $this->tariffs,
            $this->ignored,
            $categories,
            new RosterSnapshotRepository($this->pdo),
            FeesTestHelper::configuration($this->pdo, $this->tariffs, $categories, '2025-2026'),
            new ScoutYearService($this->pdo)
        );
    }

    public function testUnqualifiedIncomingRegistrationsDoNotInventAFutureFamilyTariff(): void
    {
        $this->createMember('A', 'Test', $this->coupleFeeId);
        $this->createMember('B', 'Test', $this->coupleFeeId);
        $report = $this->serviceWithIncomingRegistrations(1)->report($this->scoutYearId);
        $review = $report->indeterminate[0];
        self::assertSame([[], 2, 3, null, true], [
            $report->upcoming, $review->projectedCount->minimum, $review->projectedCount->maximum,
            $review->projectedCategory, $review->projectionIndeterminate,
        ]);
    }

    public function testTheFamilyThresholdRemainsCertainWithUnqualifiedIncomingRegistrations(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->createMember($name, 'Test', $this->familyFeeId);
        }
        $report = $this->serviceWithIncomingRegistrations(1)->report($this->scoutYearId);
        self::assertSame([[], [], []], [$report->toCorrect, $report->upcoming, $report->indeterminate]);
    }

    public function testKnownCorrectionsDoNotTurnIncompleteMembershipChecksIntoACompleteMoneyTotal(): void
    {
        $this->tariffs->save(FeeTariff::NORMAL, null, 4000);
        $this->tariffs->save(FeeTariff::FAMILY, null, 3000);
        foreach (['A', 'B', 'C'] as $name) {
            $this->createMember($name, 'Test', $this->normalFeeId);
        }
        $this->createMember('D', 'Test', $this->normalFeeId, startDate: null);
        $report = $this->service->report($this->scoutYearId);
        self::assertSame([null, null], [
            $report->toCorrect[0]->differenceCents, $report->toCorrectDifferenceCents(),
        ]);
    }
    /** @return iterable<string, array{int, int, ?HouseholdFeeCategory}> */
    public static function ranges(): iterable
    {
        yield 'all guests' => [0, 0, null];
        yield 'possibly all guests' => [0, 1, null];
        yield 'normal' => [1, 1, HouseholdFeeCategory::NORMAL];
        yield 'normal or couple' => [1, 2, null];
        yield 'couple' => [2, 2, HouseholdFeeCategory::COUPLE];
        yield 'couple or family' => [2, 3, null];
        yield 'family despite uncertainty' => [3, 4, HouseholdFeeCategory::FAMILY];
        yield 'family' => [3, 3, HouseholdFeeCategory::FAMILY];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testOnlyACertainThresholdProducesAHouseholdCategory(
        int $minimum,
        int $maximum,
        ?HouseholdFeeCategory $expected
    ): void
    {
        self::assertSame($expected, (new \Modules\Fees\Value\HouseholdFeeCount($minimum, $maximum))->category());
    }
}
