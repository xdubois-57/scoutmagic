<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

use Core\Member\MemberYearService;
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

    public function testBirthYearForSlotCountsFromTheScoutYearStartYear(): void
    {
        // Target scout year "2026-2027" ⇒ start year 2026. Louveteaux entry
        // age 8, so year-in-branch 1 ⇒ birth year 2026 - 8 = 2018.
        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel('2026-2027');
        $this->assertSame(2018, SlotMath::birthYearForSlot($this->louveteaux(), 1, $referenceYear));
        $this->assertSame(2015, SlotMath::birthYearForSlot($this->louveteaux(), 4, $referenceYear));
    }

    public function testTheBirthDayWithinTheYearNeverChangesTheSlot(): void
    {
        $brackets = [$this->baladins(), $this->louveteaux(), $this->eclaireurs()];

        $this->assertSame(
            SlotMath::slotForBirthDate($brackets, '2017-01-01', 2026),
            SlotMath::slotForBirthDate($brackets, '2017-12-31', 2026)
        );
    }

    /**
     * Issue #891: a request and a member born the same year must land in
     * the same slot, or the capacity check weighs one cohort's requests
     * against another's occupancy. Members go through
     * MemberYearService::getEffectiveAge(); requests through SlotMath —
     * checked here over every federation branch and every year in it.
     */
    public function testARequestLandsInTheSlotOfAMemberBornTheSameYear(): void
    {
        $brackets = [];
        foreach (MemberYearService::BRANCHES as $index => $branch) {
            $brackets[] = new AgeBracket(
                ageBranchId: $index + 1,
                branchLabel: $branch['name'],
                branchSortOrder: ($index + 1) * 10,
                entryAge: $branch['age_min'],
                durationYears: $branch['age_max'] - $branch['age_min'] + 1
            );
        }
        $referenceYear = MemberYearService::referenceYearFromScoutYearLabel('2026-2027');
        $memberYearService = new MemberYearService();

        foreach ($brackets as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $birthYear = SlotMath::birthYearForSlot($bracket, $yearInBranch, $referenceYear);
                $member = $memberYearService->getEffectiveAge($birthYear, 0, $referenceYear);

                $this->assertSame($bracket->branchLabel, $member->branchName);
                $this->assertSame($yearInBranch, $member->yearInBranch);
                $this->assertSame(
                    ['age_branch_id' => $bracket->ageBranchId, 'year_in_branch' => $yearInBranch],
                    SlotMath::slotForBirthDate($brackets, $birthYear . '-06-30', $referenceYear)
                );
            }
        }
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

    /**
     * The level table at the bounds the chantier names (70 %, 100 %,
     * 120 %): occupancy / capacity compared with the bound INCLUDED.
     *
     * @return array<string, array{?int, int, int, int, string}>
     */
    public static function levelCases(): array
    {
        return [
            // capacity, occupied, yes %, maybe %, level
            'empty slot' => [10, 0, 70, 100, SlotMath::LEVEL_YES],
            'just under 70 %' => [10, 6, 70, 100, SlotMath::LEVEL_YES],
            'exactly 70 % is still Oui' => [10, 7, 70, 100, SlotMath::LEVEL_YES],
            'just over 70 %' => [10, 8, 70, 100, SlotMath::LEVEL_MAYBE],
            'exactly 100 % is still Peut-être' => [10, 10, 70, 100, SlotMath::LEVEL_MAYBE],
            'just over 100 %' => [10, 11, 70, 100, SlotMath::LEVEL_NO],
            'exactly 120 % is still Peut-être' => [10, 12, 70, 120, SlotMath::LEVEL_MAYBE],
            'just over 120 %' => [10, 13, 70, 120, SlotMath::LEVEL_NO],
            'Oui beyond 100 %' => [10, 11, 110, 120, SlotMath::LEVEL_YES],
            // 70 % of 15 is 10.5 places: 10 is inside, 11 is not.
            'a bound between two places, below' => [15, 10, 70, 100, SlotMath::LEVEL_YES],
            'a bound between two places, above' => [15, 11, 70, 100, SlotMath::LEVEL_MAYBE],
            'every place of 15 taken' => [15, 15, 70, 100, SlotMath::LEVEL_MAYBE],
            'one more than 15' => [15, 16, 70, 100, SlotMath::LEVEL_NO],
            'no limit, empty' => [null, 0, 70, 100, SlotMath::LEVEL_YES],
            'no limit is always Oui, however full' => [null, 500, 70, 100, SlotMath::LEVEL_YES],
            'closed, even empty' => [0, 0, 70, 100, SlotMath::LEVEL_NO],
            'closed whatever the thresholds' => [0, 0, 119, 120, SlotMath::LEVEL_NO],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('levelCases')]
    public function testLevelForOccupancy(?int $capacity, int $occupied, int $yes, int $maybe, string $expected): void
    {
        $this->assertSame($expected, SlotMath::levelForOccupancy($capacity, $occupied, $yes, $maybe));
    }

    /**
     * The regression the nullable capacity exists to prevent: an
     * unconfigured branch reading exactly like a closed one. Same
     * occupancy, same thresholds — only the capacity differs, and the two
     * answers must be opposite.
     */
    public function testAnUnlimitedSlotIsNeverTheSameAnswerAsAClosedOne(): void
    {
        $this->assertSame(SlotMath::LEVEL_YES, SlotMath::levelForOccupancy(null, 0, 70, 100));
        $this->assertSame(SlotMath::LEVEL_NO, SlotMath::levelForOccupancy(0, 0, 70, 100));
    }

    /**
     * @return array<string, array{mixed, ?int}>
     */
    public static function thresholdInputs(): array
    {
        return [
            'the default Oui' => ['70', 70],
            'the lowest bound' => ['1', 1],
            'the highest bound' => ['120', 120],
            'spaces around' => [' 85 ', 85],
            'an integer already' => [100, 100],
            'zero' => ['0', null],
            'above the highest bound' => ['121', null],
            'a decimal' => ['70.5', null],
            'a French decimal' => ['70,5', null],
            'negative' => ['-5', null],
            'a percent sign' => ['70 %', null],
            'empty' => ['', null],
            'not a number' => ['beaucoup', null],
            'absent' => [null, null],
            'an array' => [['70'], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('thresholdInputs')]
    public function testParseThresholdPercent(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, SlotMath::parseThresholdPercent($input));
    }

    public function testThresholdPairMustBeStrictlyOrdered(): void
    {
        $this->assertTrue(SlotMath::isValidThresholdPair(70, 100));
        $this->assertTrue(SlotMath::isValidThresholdPair(119, 120));
        $this->assertFalse(
            SlotMath::isValidThresholdPair(100, 100),
            'an equal pair, leaving no room for the middle level, is refused'
        );
        $this->assertFalse(SlotMath::isValidThresholdPair(100, 70), 'an inverted pair is refused');
    }

    public function testAgeForSlotAddsYearInBranchOffsetToEntryAge(): void
    {
        $this->assertSame(8, SlotMath::ageForSlot($this->louveteaux(), 1));
        $this->assertSame(11, SlotMath::ageForSlot($this->louveteaux(), 4));
    }
}
