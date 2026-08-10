<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

use Modules\Registration\Repository\AgeBracket;
use Modules\Registration\Service\SlotMath;
use PHPUnit\Framework\TestCase;

class SlotMathTest extends TestCase
{
    private function baladins(): AgeBracket
    {
        return new AgeBracket(ageBranchId: 1, branchLabel: 'Baladins', branchSortOrder: 10, entryAge: 6, durationYears: 2);
    }

    private function louveteaux(): AgeBracket
    {
        return new AgeBracket(ageBranchId: 2, branchLabel: 'Louveteaux', branchSortOrder: 20, entryAge: 8, durationYears: 4);
    }

    private function eclaireurs(): AgeBracket
    {
        return new AgeBracket(ageBranchId: 3, branchLabel: 'Éclaireurs', branchSortOrder: 30, entryAge: 12, durationYears: 4);
    }

    public function testReferenceCalendarYearUsesStartYearWhenReferenceIsInSeptToDec(): void
    {
        $this->assertSame(2026, SlotMath::referenceCalendarYear(2026, '12-31'));
        $this->assertSame(2026, SlotMath::referenceCalendarYear(2026, '09-01'));
    }

    public function testReferenceCalendarYearUsesEndYearWhenReferenceIsInJanToAug(): void
    {
        $this->assertSame(2027, SlotMath::referenceCalendarYear(2026, '01-15'));
        $this->assertSame(2027, SlotMath::referenceCalendarYear(2026, '08-31'));
    }

    public function testBirthYearForSlotWithDefaultReferenceDate(): void
    {
        // Target scout year "2026-2027" (start year 2026), default 12-31 ⇒
        // reference calendar year 2026. Louveteaux entry age 8, so
        // year-in-branch 1 ⇒ age 8 ⇒ birth year 2026 - 8 = 2018.
        $referenceYear = SlotMath::referenceCalendarYear(2026, '12-31');
        $this->assertSame(2018, SlotMath::birthYearForSlot($this->louveteaux(), 1, $referenceYear));
        $this->assertSame(2015, SlotMath::birthYearForSlot($this->louveteaux(), 4, $referenceYear));
    }

    public function testBirthYearForSlotShiftsWithNonDefaultReferenceDate(): void
    {
        // A reference date in January falls in the scout year's SECOND
        // calendar year (2027), one later than the default's 2026 — every
        // birth year shifts by exactly one.
        $referenceYear = SlotMath::referenceCalendarYear(2026, '01-15');
        $this->assertSame(2019, SlotMath::birthYearForSlot($this->louveteaux(), 1, $referenceYear));
    }

    public function testSlotForBirthDateFindsTheCoveringBracket(): void
    {
        $brackets = [$this->baladins(), $this->louveteaux(), $this->eclaireurs()];
        $referenceYear = 2026;

        // Age 9 in 2026 ⇒ born 2017 ⇒ falls in Louveteaux (8-11), year 2.
        $slot = SlotMath::slotForBirthDate($brackets, '2017-05-01', $referenceYear);
        $this->assertSame(['age_branch_id' => 2, 'year_in_branch' => 2], $slot);
    }

    public function testSlotForBirthDateReturnsNullOutsideEveryBracket(): void
    {
        $brackets = [$this->baladins()];
        // Age 20 in 2026 ⇒ born 2006 ⇒ outside the only configured bracket.
        $this->assertNull(SlotMath::slotForBirthDate($brackets, '2006-01-01', 2026));
    }

    public function testFeederSlotWithinSameBranch(): void
    {
        $brackets = [$this->baladins(), $this->louveteaux(), $this->eclaireurs()];

        $this->assertSame(
            ['age_branch_id' => 2, 'year_in_branch' => 2],
            SlotMath::feederSlot($brackets, 2, 3)
        );
    }

    public function testFeederSlotCrossesIntoPreviousBranchAtYearOne(): void
    {
        $brackets = [$this->baladins(), $this->louveteaux(), $this->eclaireurs()];

        // Louveteaux year 1 is fed by Baladins' LAST year (2).
        $this->assertSame(
            ['age_branch_id' => 1, 'year_in_branch' => 2],
            SlotMath::feederSlot($brackets, 2, 1)
        );
    }

    public function testFeederSlotIsNullForTheFirstYearOfTheFirstBranch(): void
    {
        $brackets = [$this->baladins(), $this->louveteaux()];

        $this->assertNull(SlotMath::feederSlot($brackets, 1, 1));
    }

    public function testTierForRemainingAboveAvailableThreshold(): void
    {
        // capacity 10, remaining 6 ⇒ ratio 0.6 ⇒ >= 0.5 available threshold.
        $this->assertSame(SlotMath::TIER_AVAILABLE, SlotMath::tierForRemaining(10, 6, 0.5, 0.1));
    }

    public function testTierForRemainingBetweenThresholds(): void
    {
        // capacity 10, remaining 2 ⇒ ratio 0.2 ⇒ between 0.1 and 0.5.
        $this->assertSame(SlotMath::TIER_LIMITED, SlotMath::tierForRemaining(10, 2, 0.5, 0.1));
    }

    public function testTierForRemainingBelowLimitedThreshold(): void
    {
        // capacity 10, remaining 0 (or negative — oversubscribed) ⇒ heavy.
        $this->assertSame(SlotMath::TIER_HEAVY, SlotMath::tierForRemaining(10, 0, 0.5, 0.1));
        $this->assertSame(SlotMath::TIER_HEAVY, SlotMath::tierForRemaining(10, -3, 0.5, 0.1));
    }

    public function testTierForRemainingIsHeavyWhenCapacityIsZero(): void
    {
        $this->assertSame(SlotMath::TIER_HEAVY, SlotMath::tierForRemaining(0, 0, 0.5, 0.1));
    }

    public function testAgeForSlotAddsYearInBranchOffsetToEntryAge(): void
    {
        $this->assertSame(8, SlotMath::ageForSlot($this->louveteaux(), 1));
        $this->assertSame(11, SlotMath::ageForSlot($this->louveteaux(), 4));
    }
}
