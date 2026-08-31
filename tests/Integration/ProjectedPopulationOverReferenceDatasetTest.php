<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Registration\Api\ProjectedPerson;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\ProjectedMemberEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SectionTransferRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\ForecastService;
use Modules\Registration\Service\PassageService;
use Modules\Registration\Service\PassageStatisticsService;
use Modules\Registration\Service\ProjectedPopulationService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * `Api\ProjectedPopulationProvider` against the reference unit — ~180
 * real members across three imported years, not a fixture written to make
 * a number come out.
 *
 * The synthetic tests
 * (Tests\Modules\Registration\Service\ProjectedPopulationServiceTest) pin
 * the contract's edges one apport at a time. This one asks the only
 * question that matters at scale: **does the interface agree with the page
 * on a real population?** A façade that drifted by one person on a unit
 * this size would be invisible in a four-person fixture and would show up
 * here.
 *
 * @group database
 */
final class ProjectedPopulationOverReferenceDatasetTest extends TestCase
{
    private \PDO $pdo;
    private ForecastService $forecastService;
    private ProjectedPopulationService $provider;
    private SectionService $sectionService;

    /** @var array<string, int> */
    private array $yearIds;

    private string $currentLabel;
    private string $targetLabel;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        // The Desk replay builds core's tables' worth of data; the
        // registration module's own tables are not part of core's schema
        // and are created the same way every other test of this module
        // creates them.
        RegistrationTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $replay = new DeskImportReplay($this->pdo, $encryption, dirname(__DIR__) . '/fixtures/reference-dataset');
        $this->yearIds = $replay->ensureYears();
        $replay->importAll($this->yearIds, 1);
        $replay->confirmFunctionRoles($this->yearIds);

        // The last two imported years: the projection always looks one year
        // ahead of a year that exists, and the dataset's final year is the
        // one with real Desk rows to be certain about.
        $this->currentLabel = UnitBlueprint::YEARS[count(UnitBlueprint::YEARS) - 2];
        $this->targetLabel = UnitBlueprint::YEARS[count(UnitBlueprint::YEARS) - 1];

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $this->sectionService = $sectionService;
        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);

        $passageService = new PassageService(
            $this->pdo, $encryption, $sectionService,
            new SectionTransferRepository($this->pdo), $requestRepository, $ageBracketRepository
        );
        $this->forecastService = new ForecastService($this->pdo, $encryption, $sectionService, $passageService);

        $this->provider = new ProjectedPopulationService(
            $this->forecastService,
            new SlotService(
                $this->pdo, $encryption, new SettingService(new SettingRepository($this->pdo)),
                $ageBracketRepository, new SlotCapacityRepository($this->pdo), $requestRepository
            ),
            new ScoutYearService($this->pdo),
            $sectionService,
            $requestRepository,
            new ProjectedMemberEmailRepository($this->pdo, $encryption)
        );
    }

    public function testTheUnitIsBigEnoughForThisToProveAnything(): void
    {
        self::assertGreaterThan(
            100,
            count($this->population()),
            'A projection of a handful of people would let a drift of one hide.'
        );
    }

    public function testTheHeadcountIsTheOneThePageShows(): void
    {
        self::assertSame(
            $this->forecast()['summary']['projected_total'],
            count($this->population())
        );
    }

    public function testEverySectionAgreesWithThePageDownToTheGenderSplit(): void
    {
        $expected = [];
        foreach ($this->forecast()['sections'] as $section) {
            if ($section['total'] > 0) {
                $expected[$section['id']] = [
                    'total' => $section['total'],
                    'certain' => $section['certain_total'],
                    'hypothesis' => $section['hypothesis_total'],
                    'gender' => $section['gender'],
                ];
            }
        }
        self::assertNotSame([], $expected, 'The dataset must project somebody somewhere.');

        $actual = [];
        foreach ($this->provider->projectedSectionTotals($this->targetYearId()) as $totals) {
            $actual[$totals->sectionId] = [
                'total' => $totals->total,
                'certain' => $totals->certainTotal,
                'hypothesis' => $totals->hypothesisTotal,
                'gender' => $totals->gender,
            ];
        }

        self::assertSame($expected, $actual);
    }

    public function testTheSectionsAndTheUnassignedAccountForEverybodyExactlyOnce(): void
    {
        $population = $this->population();

        $inSections = 0;
        foreach ($this->provider->projectedSectionTotals($this->targetYearId()) as $totals) {
            $inSections += $totals->total;
        }

        $unassigned = count(array_filter(
            $population,
            static fn (ProjectedPerson $person): bool => $person->sectionId === null
        ));

        // ForecastService's first invariant, restated over the interface:
        // a person can be counted twice or fall between two counters only
        // if this stops holding.
        self::assertSame(count($population), $inSections + $unassigned + $this->unknownSectionCount($population));
        self::assertSame($this->forecast()['unassigned']['total'], $unassigned + $this->unknownSectionCount($population));
    }

    public function testEverybodyIsEitherAMemberOrARequestAndNeverBoth(): void
    {
        foreach ($this->population() as $person) {
            self::assertTrue(($person->memberId === null) !== ($person->registrationRequestId === null));
        }
    }

    public function testNobodyIsProjectedTwice(): void
    {
        $seen = [];
        foreach ($this->population() as $person) {
            $key = $person->memberId !== null ? 'm' . $person->memberId : 'r' . $person->registrationRequestId;
            self::assertArrayNotHasKey($key, $seen, "{$key} appears twice in the projection.");
            $seen[$key] = true;
        }
    }

    public function testTheReachableAddressesAreASubsetOfTheProjectedPeople(): void
    {
        $projected = [];
        foreach ($this->population() as $person) {
            $projected[$person->memberId !== null ? 'm' . $person->memberId : 'r' . $person->registrationRequestId] = true;
        }

        $recipients = $this->provider->reachableRecipients($this->targetYearId());
        self::assertNotSame([], $recipients, 'The reference unit has addresses; getting none back is a defect, not a quiet year.');

        foreach ($recipients as $recipient) {
            $key = $recipient->memberId !== null ? 'm' . $recipient->memberId : 'r' . $recipient->registrationRequestId;
            self::assertArrayHasKey(
                $key,
                $projected,
                'Writing to somebody the projection does not contain is writing to the wrong year.'
            );
            self::assertNotSame('', $recipient->email);
        }

        self::assertLessThanOrEqual(count($projected), count($recipients));
    }

    /**
     * IT-12's own requirement: the Passage page's statistics box must show
     * the section totals the Prévisions page shows for the same target
     * year. Two pages of one site disagreeing about next year's Louveteaux
     * is the failure this whole Api\ layer exists to make impossible, and
     * a fixture of four people would not catch a drift of one.
     */
    public function testThePassageBoxAgreesWithThePrevisionsPageSectionBySection(): void
    {
        $box = (new PassageStatisticsService($this->sectionService, $this->provider))
            ->forTargetYear($this->targetYearId());

        $expected = [];
        foreach ($this->forecast()['sections'] as $section) {
            if ($section['total'] > 0) {
                $expected[$section['id']] = $section['total'];
            }
        }
        self::assertNotSame([], $expected);

        $actual = [];
        foreach ($box['branches'] as $branch) {
            foreach ($branch['sections'] as $section) {
                $actual[$section['id']] = $section['scopes'][PassageStatisticsService::SCOPE_PROJECTED]['total'];
            }
        }
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    public function testThePassageBoxCountsTheSameUnassignedPeopleAsThePrevisionsPage(): void
    {
        $box = (new PassageStatisticsService($this->sectionService, $this->provider))
            ->forTargetYear($this->targetYearId());

        self::assertSame(
            $this->forecast()['unassigned']['total'],
            $box['unassigned'][PassageStatisticsService::SCOPE_PROJECTED]
        );
    }

    public function testArrivalsOnlyIsASubsetOfTheProjectedScopeOnARealUnit(): void
    {
        $box = (new PassageStatisticsService($this->sectionService, $this->provider))
            ->forTargetYear($this->targetYearId());

        foreach ($box['branches'] as $branch) {
            self::assertLessThanOrEqual(
                $branch['scopes'][PassageStatisticsService::SCOPE_PROJECTED]['total'],
                $branch['scopes'][PassageStatisticsService::SCOPE_ARRIVALS]['total'],
                "« Arrivées seules » can never hold more people than the whole projection."
            );
        }
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @return array<int, ProjectedPerson>
     */
    private function population(): array
    {
        return $this->provider->projectedPopulation($this->targetYearId());
    }

    /**
     * @return array{summary: array<string, int>, sections: array<int, array<string, mixed>>, unassigned: array<string, int>, pyramid: array<int, array<string, int>>, pyramid_max: int}
     */
    private function forecast(): array
    {
        return $this->forecastService->getForecast(
            $this->yearIds[$this->currentLabel],
            $this->currentLabel,
            $this->targetYearId(),
            $this->targetLabel,
            '12-31'
        );
    }

    private function targetYearId(): int
    {
        return $this->yearIds[$this->targetLabel];
    }

    /**
     * People pointing at a section that no longer exists — its own bucket
     * on the page, and counted by no section here either.
     *
     * @param array<int, ProjectedPerson> $population
     */
    private function unknownSectionCount(array $population): int
    {
        $known = [];
        foreach ($this->provider->projectedSectionTotals($this->targetYearId()) as $totals) {
            $known[$totals->sectionId] = true;
        }

        $count = 0;
        foreach ($population as $person) {
            if ($person->sectionId !== null && !isset($known[$person->sectionId])) {
                $count++;
            }
        }

        return $count;
    }
}
