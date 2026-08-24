<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\CandidateDetector;
use Modules\Leadership\Service\ObligationsService;
use Modules\Leadership\Service\StewardService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

class StewardServiceTest extends TestCase
{
    private const SCOUT_YEAR_ID = 3;

    /** A day inside the September-to-May regime. */
    private const WINTER_DAY = '2026-03-10';

    /**
     * @param array<int, ?string> $firstSeenByMemberId
     */
    private function service(array $firstSeenByMemberId = []): StewardService
    {
        $repository = $this->createStub(LeadershipRepository::class);
        // One batched lookup for the whole list, not one call per line —
        // the page's whole content is that list.
        $repository->method('findEarliestSectionPeriodStarts')
            ->willReturnCallback(
                static function (array $memberIds, int $scoutYearId) use ($firstSeenByMemberId): array {
                    $found = [];
                    foreach ($memberIds as $memberId) {
                        if (($firstSeenByMemberId[$memberId] ?? null) !== null) {
                            $found[(int) $memberId] = (string) $firstSeenByMemberId[$memberId];
                        }
                    }

                    return $found;
                }
            );

        return new StewardService($repository, new ObligationsService(new CandidateDetector()));
    }

    private function winter(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::WINTER_DAY);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function steward(array $overrides = []): \Modules\Leadership\Value\StaffFunctionRow
    {
        return LeadershipTestHelper::staffRow(array_merge([
            'functionRole' => 'intendant',
            'functionLabel' => 'Intendant',
        ], $overrides));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function regimeDates(): array
    {
        return [
            'the day before summer' => ['2026-05-31', false],
            'first day of summer' => ['2026-06-01', true],
            'mid-camp' => ['2026-07-20', true],
            'last day of summer' => ['2026-08-31', true],
            'first day of the school year' => ['2026-09-01', false],
            'deep winter' => ['2026-01-15', false],
        ];
    }

    #[DataProvider('regimeDates')]
    public function testTheSummerRegimeRunsFromJuneToAugustInclusive(string $day, bool $expected): void
    {
        $this->assertSame($expected, $this->service()->isSummerRegime(new \DateTimeImmutable($day)));
    }

    public function testCountsDaysFromTheFunctionStartDate(): void
    {
        $lines = $this->service()->registrations(
            [$this->steward(['functionStartDate' => '2026-03-01'])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertCount(1, $lines);
        $this->assertSame(9, $lines[0]->days);
        $this->assertStringContainsString('01/03/2026', (string) $lines[0]->note);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function severityThresholds(): array
    {
        return [
            'below the warning line' => [LeadershipRules::STEWARD_WARNING_DAYS - 1, 'normal'],
            'at the warning line' => [LeadershipRules::STEWARD_WARNING_DAYS, 'warning'],
            'between the two' => [LeadershipRules::STEWARD_CRITICAL_DAYS - 1, 'warning'],
            'at the critical line' => [LeadershipRules::STEWARD_CRITICAL_DAYS, 'critical'],
            'well past it' => [LeadershipRules::STEWARD_CRITICAL_DAYS + 40, 'critical'],
        ];
    }

    #[DataProvider('severityThresholds')]
    public function testSeverityFollowsTheConfiguredThresholds(int $days, string $expected): void
    {
        $start = $this->winter()->modify('-' . $days . ' days')->format('Y-m-d');

        $lines = $this->service()->registrations(
            [$this->steward(['functionStartDate' => $start])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertSame($days, $lines[0]->days);
        $this->assertSame($expected, $lines[0]->severity);
    }

    public function testLongestRunningFirst(): void
    {
        $lines = $this->service()->registrations([
            $this->steward(['memberId' => 1, 'firstName' => 'Aaa', 'functionStartDate' => '2026-03-05']),
            $this->steward(['memberId' => 2, 'firstName' => 'Bbb', 'functionStartDate' => '2026-01-05']),
        ], self::SCOUT_YEAR_ID, $this->winter());

        $this->assertSame([64, 5], array_map(static fn ($l) => $l->days, $lines));
    }

    /**
     * The documented fallback: no Desk start date, so the site uses the
     * only "first appearance" it actually keeps — and says on the line
     * that this is what it is doing, because presenting it as a Desk
     * registration date would be a fact the data does not support.
     */
    public function testFallsBackToFirstAppearanceAndSaysSo(): void
    {
        $lines = $this->service([42 => '2026-02-20'])->registrations(
            [$this->steward(['memberId' => 42, 'functionStartDate' => null])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertSame(18, $lines[0]->days);
        $this->assertStringContainsString('première apparition', (string) $lines[0]->note);
        $this->assertStringContainsString("pas une date d'inscription Desk", (string) $lines[0]->note);
    }

    public function testTheRealDeskDateIsNeverLabelledApproximate(): void
    {
        $lines = $this->service([42 => '2026-02-20'])->registrations(
            [$this->steward(['memberId' => 42, 'functionStartDate' => '2026-03-01'])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertStringNotContainsString('première apparition', (string) $lines[0]->note);
    }

    /**
     * With neither date there is no countdown at all. Inventing one from
     * the scout year's start would produce a plausible number that is
     * simply not a fact about this person.
     */
    public function testNoDateAtAllMeansNoCountdown(): void
    {
        $lines = $this->service()->registrations(
            [$this->steward(['memberId' => 42, 'functionStartDate' => null])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertCount(1, $lines);
        $this->assertNull($lines[0]->days);
        $this->assertStringContainsString('impossible de compter les jours', (string) $lines[0]->note);
    }

    public function testAStartDateInTheFutureIsAnnouncedRatherThanCountedAsZero(): void
    {
        // "Inscrit depuis 0 jour" reads as "registered today", which is a
        // different — and wrong — statement about somebody whose function
        // begins next month.
        $lines = $this->service()->registrations(
            [$this->steward(['functionStartDate' => '2026-04-01'])],
            self::SCOUT_YEAR_ID,
            $this->winter()
        );

        $this->assertNull($lines[0]->days);
        $this->assertSame('Inscription à partir du 01/04/2026.', $lines[0]->note);
        $this->assertStringNotContainsString('Inscrit depuis', (string) $lines[0]->note);
    }

    /**
     * Under the summer regime the lines carry no countdown at all — not
     * even a quiet one. The banner above them says none is shown, and a
     * page contradicting its own banner is worse than either half alone.
     */
    public function testTheSummerRegimeShowsNoCountdownAnywhere(): void
    {
        $summer = new \DateTimeImmutable('2026-07-20');

        $lines = $this->service()->registrations([
            $this->steward(['memberId' => 1, 'functionStartDate' => '2026-01-05']),
        ], self::SCOUT_YEAR_ID, $summer);

        $this->assertCount(1, $lines);
        $this->assertNull($lines[0]->days);
        $this->assertSame('normal', $lines[0]->severity);
        $this->assertStringNotContainsString('jour', (string) $lines[0]->note);
        // The date itself is a fact, not a countdown, so it stays.
        $this->assertStringContainsString('05/01/2026', (string) $lines[0]->note);
    }

    public function testTheSummerLineStillDeclaresAnApproximateDate(): void
    {
        $lines = $this->service([42 => '2026-02-20'])->registrations(
            [$this->steward(['memberId' => 42, 'functionStartDate' => null])],
            self::SCOUT_YEAR_ID,
            new \DateTimeImmutable('2026-07-20')
        );

        $this->assertStringContainsString('première apparition sur le site', (string) $lines[0]->note);
        $this->assertStringNotContainsString('jour', (string) $lines[0]->note);
    }

    public function testTheSummerLineWithNoDateAtAllSaysSo(): void
    {
        $lines = $this->service()->registrations(
            [$this->steward(['memberId' => 42, 'functionStartDate' => null])],
            self::SCOUT_YEAR_ID,
            new \DateTimeImmutable('2026-07-20')
        );

        $this->assertSame("Aucune date de début n'est encodée dans Desk.", $lines[0]->note);
    }

    public function testAnimatorsAreNotStewards(): void
    {
        $lines = $this->service()->registrations([
            LeadershipTestHelper::staffRow(['functionRole' => 'chief', 'functionStartDate' => '2026-01-01']),
        ], self::SCOUT_YEAR_ID, $this->winter());

        $this->assertSame([], $lines);
    }

    /**
     * A change of intendance function restarts the count. Accepted, not
     * worked around: in practice it is a fresh registration, and stitching
     * two periods together would mean deciding which changes count as
     * continuous — a judgement the data cannot support.
     */
    public function testTwoIntendanceFunctionsAreTwoRegistrations(): void
    {
        $lines = $this->service()->registrations([
            $this->steward(['memberId' => 7, 'memberFunctionId' => 1, 'functionStartDate' => '2026-01-05']),
            $this->steward(['memberId' => 7, 'memberFunctionId' => 2, 'functionStartDate' => '2026-03-05']),
        ], self::SCOUT_YEAR_ID, $this->winter());

        $this->assertCount(2, $lines);
    }

    public function testFlagsStewardsBelowTheMinimumAge(): void
    {
        $today = $this->winter();

        $lines = $this->service()->underAgeStewards([
            $this->steward([
                'memberId' => 1,
                'lastName' => 'TropJeune',
                'birthDate' => LeadershipTestHelper::birthDateForAge(LeadershipRules::STEWARD_MIN_AGE - 1, $today),
            ]),
            $this->steward([
                'memberId' => 2,
                'lastName' => 'PileALage',
                'birthDate' => LeadershipTestHelper::birthDateForAge(LeadershipRules::STEWARD_MIN_AGE, $today),
            ]),
            $this->steward(['memberId' => 3, 'lastName' => 'SansDate', 'birthDate' => null]),
        ], $today);

        $this->assertCount(1, $lines);
        $this->assertSame('Camille TropJeune', $lines[0]->fullName);
        $this->assertStringContainsString((string) LeadershipRules::STEWARD_MIN_AGE, (string) $lines[0]->note);
    }

    public function testAnUnknownAgeIsNeverReportedAsUnderAge(): void
    {
        $lines = $this->service()->underAgeStewards(
            [$this->steward(['birthDate' => null])],
            $this->winter()
        );

        $this->assertSame([], $lines);
    }
}
