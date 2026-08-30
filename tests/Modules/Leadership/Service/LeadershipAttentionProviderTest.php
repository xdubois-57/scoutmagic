<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Core\Attention\AttentionPoint;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\LeadershipAttentionProvider;
use Modules\Leadership\Service\StewardService;
use Modules\Leadership\Value\PersonLine;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Leadership\LeadershipTestHelper;

/**
 * What this module contributes to the attention points page — and, just as
 * deliberately, what it does not.
 *
 * The steward countdown is stubbed rather than computed: the provider asks
 * `StewardService` for the regime and the lines, and both answers depend on
 * the real date. A test that let them depend on it would pass or fail
 * according to the month it is run in — the free window does not apply
 * between June and August. What the service itself computes is
 * `StewardServiceTest`'s subject, not this one's.
 */
class LeadershipAttentionProviderTest extends TestCase
{
    private const SCOUT_YEAR_ID = 3;

    /**
     * A staff list that would once have produced a supervision point too:
     * a single animateur, no brevet, on a section of its own.
     *
     * @return list<\Modules\Leadership\Value\StaffFunctionRow>
     */
    private function staff(): array
    {
        return [
            LeadershipTestHelper::staffRow([
                'memberId' => 1,
                'functionRole' => 'chief',
                'sectionName' => 'Louveteaux',
            ]),
            LeadershipTestHelper::staffRow([
                'memberId' => 2,
                'memberYearId' => 20,
                'memberFunctionId' => 200,
                'firstName' => 'Sacha',
                'lastName' => 'Martin',
                'functionRole' => 'intendant',
                'functionLabel' => 'Intendant',
                'sectionName' => null,
            ]),
        ];
    }

    /**
     * @param list<PersonLine> $registrations
     */
    private function provider(array $registrations, bool $summer = false): LeadershipAttentionProvider
    {
        $repository = $this->createStub(LeadershipRepository::class);
        $repository->method('findStaffFunctions')->willReturn($this->staff());

        $stewards = $this->createStub(StewardService::class);
        $stewards->method('isSummerRegime')->willReturn($summer);
        $stewards->method('registrations')->willReturn($registrations);

        return new LeadershipAttentionProvider($repository, $stewards);
    }

    private function stewardLine(int $days): PersonLine
    {
        return new PersonLine(
            memberYearId: 20,
            totem: null,
            fullName: 'Sacha Martin',
            detail: 'Intendant',
            note: 'Inscrit depuis ' . $days . ' jours.',
            severity: 'warning',
            days: $days,
        );
    }

    /**
     * @param list<AttentionPoint> $points
     * @return list<string>
     */
    private function titles(array $points): array
    {
        return array_map(static fn (AttentionPoint $p): string => $p->title, $points);
    }

    public function testTheSourceIsNamedForTheReader(): void
    {
        $this->assertSame('Encadrement', $this->provider([])->sourceLabel());
    }

    /**
     * The point this module used to raise on the ONE ratio is gone, and
     * the one it raises about intendants is not. The staff list here is
     * the one that used to produce both.
     */
    public function testReportsStewardsRunningOutAndNothingAboutTheOneRatio(): void
    {
        $points = $this->provider([$this->stewardLine(LeadershipRules::STEWARD_WARNING_DAYS)])
            ->collect(self::SCOUT_YEAR_ID);

        $this->assertCount(1, $points);
        $this->assertSame('Sacha Martin est intendant depuis 21 jours', $points[0]->title);
        $this->assertSame('/admin/leadership/stewards', $points[0]->actionUrl);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);

        foreach ($this->titles($points) as $title) {
            $this->assertStringNotContainsString('encadrés en nombre suffisant', $title);
        }
    }

    /**
     * With nothing to say about the intendants, the module says nothing at
     * all. A supervision point would surface here if one were still being
     * produced — the staff list is short of animateurs and holds no
     * brevet.
     */
    public function testContributesNothingWhenNoStewardIsRunningOut(): void
    {
        $this->assertSame([], $this->provider([])->collect(self::SCOUT_YEAR_ID));

        $belowTheLine = $this->stewardLine(LeadershipRules::STEWARD_WARNING_DAYS - 1);
        $this->assertSame([], $this->provider([$belowTheLine])->collect(self::SCOUT_YEAR_ID));
    }

    /**
     * Same under the summer regime, where the countdown does not apply:
     * the page carries nothing from this module rather than a point about
     * supervision instead.
     */
    public function testTheSummerRegimeSilencesTheWholeProvider(): void
    {
        $points = $this->provider(
            [$this->stewardLine(LeadershipRules::STEWARD_CRITICAL_DAYS)],
            summer: true
        )->collect(self::SCOUT_YEAR_ID);

        $this->assertSame([], $points);
    }

    /**
     * The deadline is what sorts this point to the top of the page, so it
     * has to be there — and it has to be the end of the free window, not
     * the day the countdown crossed the warning line.
     */
    public function testTheDueDateIsTheEndOfTheFreeWindow(): void
    {
        $days = LeadershipRules::STEWARD_WARNING_DAYS;
        $points = $this->provider([$this->stewardLine($days)])->collect(self::SCOUT_YEAR_ID);

        $this->assertNotNull($points[0]->dueDate);
        $this->assertSame(
            LeadershipRules::STEWARD_FREE_DAYS - $days,
            $points[0]->daysUntilDue(\Core\Config\AppClock::now())
        );
    }
}
