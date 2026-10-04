<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Availability;

use Modules\Rental\Availability\BlockDayPlanner;
use Modules\Rental\Repository\RentalBlock;
use PHPUnit\Framework\TestCase;

/**
 * Days blocked or released on the managed calendar become periods again
 * (#708, IT-07): extended, cut, removed, merged only on the same reason —
 * and an undo rebuilds exactly what was there.
 */
class BlockDayPlannerTest extends TestCase
{
    private BlockDayPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new BlockDayPlanner();
    }

    private function block(int $id, string $start, string $end, ?string $reason = null): RentalBlock
    {
        return new RentalBlock($id, 1, $start, $end, $reason, null, new \DateTimeImmutable('2027-01-01'));
    }

    /**
     * @param list<array{start: string, end: string, reason: string|null}> $periods
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    private static function flat(array $periods): array
    {
        return array_map(static fn(array $p): array => [$p['start'], $p['end'], $p['reason']], $periods);
    }

    public function testDaysBlockedFarFromAnyPeriodBecomeANewPeriodWithoutAReason(): void
    {
        $plan = $this->planner->plan([], ['2027-07-12', '2027-07-10', '2027-07-11'], BlockDayPlanner::MODE_BLOCK);

        $this->assertSame([], $plan->replacedBlockIds);
        $this->assertSame([['2027-07-10', '2027-07-12', null]], self::flat($plan->periods));
        $this->assertSame(['2027-07-10' => null, '2027-07-11' => null, '2027-07-12' => null], $plan->changed);
    }

    public function testDaysBlockedNextToAPeriodExtendItAndTakeItsReason(): void
    {
        $plan = $this->planner->plan(
            [$this->block(7, '2027-07-10', '2027-07-12', 'Camp')],
            ['2027-07-13', '2027-07-14'],
            BlockDayPlanner::MODE_BLOCK
        );

        $this->assertSame([7], $plan->replacedBlockIds);
        $this->assertSame([['2027-07-10', '2027-07-14', 'Camp']], self::flat($plan->periods));
        $this->assertSame(['2027-07-13' => 'Camp', '2027-07-14' => 'Camp'], $plan->changed);
    }

    public function testDaysBlockedJustBeforeAPeriodExtendItBackwards(): void
    {
        $plan = $this->planner->plan(
            [$this->block(7, '2027-07-10', '2027-07-12', 'Camp')],
            ['2027-07-08', '2027-07-09'],
            BlockDayPlanner::MODE_BLOCK
        );

        $this->assertSame([['2027-07-08', '2027-07-12', 'Camp']], self::flat($plan->periods));
    }

    public function testReleasingTheMiddleCutsAPeriodInTwoEachKeepingItsReason(): void
    {
        $plan = $this->planner->plan(
            [$this->block(7, '2027-07-10', '2027-07-14', 'Camp')],
            ['2027-07-12'],
            BlockDayPlanner::MODE_RELEASE
        );

        $this->assertSame(
            [['2027-07-10', '2027-07-11', 'Camp'], ['2027-07-13', '2027-07-14', 'Camp']],
            self::flat($plan->periods)
        );
        $this->assertSame(['2027-07-12' => 'Camp'], $plan->changed);
    }

    public function testReleasingAWholePeriodRemovesIt(): void
    {
        $plan = $this->planner->plan(
            [$this->block(7, '2027-07-10', '2027-07-11')],
            ['2027-07-10', '2027-07-11'],
            BlockDayPlanner::MODE_RELEASE
        );

        $this->assertSame([7], $plan->replacedBlockIds);
        $this->assertSame([], $plan->periods);
    }

    public function testBridgingTwoPeriodsWithTheSameReasonMergesThem(): void
    {
        $plan = $this->planner->plan(
            [$this->block(1, '2027-07-10', '2027-07-11'), $this->block(2, '2027-07-14', '2027-07-15')],
            ['2027-07-12', '2027-07-13'],
            BlockDayPlanner::MODE_BLOCK
        );

        $this->assertSame([1, 2], $plan->replacedBlockIds);
        $this->assertSame([['2027-07-10', '2027-07-15', null]], self::flat($plan->periods));
    }

    public function testAPeriodWithAReasonNeverSwallowsOneWithAnother(): void
    {
        $plan = $this->planner->plan(
            [$this->block(1, '2027-07-10', '2027-07-11', 'Camp'), $this->block(2, '2027-07-14', '2027-07-15', 'Travaux')],
            ['2027-07-12', '2027-07-13'],
            BlockDayPlanner::MODE_BLOCK
        );

        $this->assertSame(
            [['2027-07-10', '2027-07-13', 'Camp'], ['2027-07-14', '2027-07-15', 'Travaux']],
            self::flat($plan->periods)
        );
    }

    public function testPeriodsFarFromTheGestureAreLeftAlone(): void
    {
        $plan = $this->planner->plan(
            [$this->block(1, '2027-07-01', '2027-07-02'), $this->block(2, '2027-07-03', '2027-07-04')],
            ['2027-07-20'],
            BlockDayPlanner::MODE_BLOCK
        );

        $this->assertSame([], $plan->replacedBlockIds);
        $this->assertSame([['2027-07-20', '2027-07-20', null]], self::flat($plan->periods));
    }

    public function testAGestureThatChangesNothingPlansNothing(): void
    {
        $blocks = [$this->block(1, '2027-07-10', '2027-07-12')];

        $this->assertTrue($this->planner->plan($blocks, ['2027-07-11'], BlockDayPlanner::MODE_BLOCK)->isEmpty());
        $this->assertTrue($this->planner->plan($blocks, ['2027-07-20'], BlockDayPlanner::MODE_RELEASE)->isEmpty());
        $this->assertTrue($this->planner->plan($blocks, [], BlockDayPlanner::MODE_BLOCK)->isEmpty());
    }

    public function testUndoingABlockGestureRestoresThePeriods(): void
    {
        $before = [$this->block(1, '2027-07-10', '2027-07-11', 'Camp')];
        $plan = $this->planner->plan($before, ['2027-07-12', '2027-07-13'], BlockDayPlanner::MODE_BLOCK);

        $after = self::asBlocks($plan->periods);
        $undo = $this->planner->plan($after, array_keys($plan->changed), BlockDayPlanner::MODE_RELEASE, $plan->changed);

        $this->assertSame([['2027-07-10', '2027-07-11', 'Camp']], self::flat($undo->periods));
    }

    public function testUndoingAReleaseGestureRestoresThePeriodWithItsReason(): void
    {
        $before = [$this->block(1, '2027-07-10', '2027-07-14', 'Camp')];
        $plan = $this->planner->plan($before, ['2027-07-11', '2027-07-12'], BlockDayPlanner::MODE_RELEASE);

        $after = self::asBlocks($plan->periods);
        $undo = $this->planner->plan($after, array_keys($plan->changed), BlockDayPlanner::MODE_BLOCK, $plan->changed);

        $this->assertSame([['2027-07-10', '2027-07-14', 'Camp']], self::flat($undo->periods));
    }

    /**
     * @param list<array{start: string, end: string, reason: string|null}> $periods
     * @return RentalBlock[]
     */
    private static function asBlocks(array $periods): array
    {
        $blocks = [];
        foreach ($periods as $i => $period) {
            $blocks[] = new RentalBlock(
                100 + $i,
                1,
                $period['start'],
                $period['end'],
                $period['reason'],
                null,
                new \DateTimeImmutable('2027-01-01')
            );
        }

        return $blocks;
    }
}
