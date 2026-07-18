<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberYearService;
use PHPUnit\Framework\TestCase;

/**
 * MemberYearService::getEffectiveAge() is the single source of truth for
 * "which branch-year is this member in" — offset -1/0/+1 and the branch
 * boundaries themselves are exercised here.
 */
class MemberYearServiceTest extends TestCase
{
    private const REFERENCE_YEAR = 2026;

    private MemberYearService $service;

    protected function setUp(): void
    {
        $this->service = new MemberYearService();
    }

    public function testNormalOffsetUsesRawAge(): void
    {
        // Born 2017 → age 9 in 2026 → louveteaux, 2e année.
        $result = $this->service->getEffectiveAge(2017, 0, self::REFERENCE_YEAR);

        $this->assertSame(9, $result->age);
        $this->assertSame('louveteau', $result->branchKey);
        $this->assertSame('Louveteaux', $result->branchName);
        $this->assertSame(2, $result->yearInBranch);
        $this->assertSame(4, $result->totalYearsInBranch);
        $this->assertSame('2e année louveteaux', $result->getBranchYearLabel());
    }

    public function testMinusOneOffsetShiftsAMemberDownAYear(): void
    {
        // Born 2014 → raw age 12 (would be éclaireurs 1ère année), offset -1 →
        // effective age 11 → louveteaux, 4e année.
        $result = $this->service->getEffectiveAge(2014, -1, self::REFERENCE_YEAR);

        $this->assertSame(11, $result->age);
        $this->assertSame('louveteau', $result->branchKey);
        $this->assertSame(4, $result->yearInBranch);
        $this->assertSame('4e année louveteaux', $result->getBranchYearLabel());
    }

    public function testPlusOneOffsetShiftsAMemberUpAYear(): void
    {
        // Born 2018 → raw age 8 (louveteaux 1ère année), offset +1 → effective
        // age 9 → louveteaux, 2e année.
        $result = $this->service->getEffectiveAge(2018, 1, self::REFERENCE_YEAR);

        $this->assertSame(9, $result->age);
        $this->assertSame('louveteau', $result->branchKey);
        $this->assertSame(2, $result->yearInBranch);
    }

    public function testPlusOneOffsetCanPushAMemberIntoTheNextBranch(): void
    {
        // Born 2015 → raw age 11 (louveteaux 4e année), offset +1 → effective
        // age 12 → éclaireurs, 1ère année.
        $result = $this->service->getEffectiveAge(2015, 1, self::REFERENCE_YEAR);

        $this->assertSame(12, $result->age);
        $this->assertSame('eclaireur', $result->branchKey);
        $this->assertSame(1, $result->yearInBranch);
        $this->assertSame('1ère année éclaireurs', $result->getBranchYearLabel());
    }

    public function testBranchLowerBoundary(): void
    {
        // Baladins starts at age 6.
        $result = $this->service->getEffectiveAge(2020, 0, self::REFERENCE_YEAR);

        $this->assertSame(6, $result->age);
        $this->assertSame('baladin', $result->branchKey);
        $this->assertSame(1, $result->yearInBranch);
    }

    public function testBranchUpperBoundary(): void
    {
        // Pionniers ends at age 17.
        $result = $this->service->getEffectiveAge(2009, 0, self::REFERENCE_YEAR);

        $this->assertSame(17, $result->age);
        $this->assertSame('pionnier', $result->branchKey);
        $this->assertSame(2, $result->yearInBranch);
        $this->assertSame(2, $result->totalYearsInBranch);
    }

    public function testAgeBelowEveryBranchHasNoBranch(): void
    {
        // Age 5: below Baladins.
        $result = $this->service->getEffectiveAge(2021, 0, self::REFERENCE_YEAR);

        $this->assertSame(5, $result->age);
        $this->assertNull($result->branchKey);
        $this->assertFalse($result->isInKnownBranch());
        $this->assertSame('', $result->getBranchYearLabel());
    }

    public function testAgeAboveEveryBranchHasNoBranch(): void
    {
        // Age 18: above Pionniers.
        $result = $this->service->getEffectiveAge(2008, 0, self::REFERENCE_YEAR);

        $this->assertSame(18, $result->age);
        $this->assertNull($result->branchKey);
        $this->assertFalse($result->isInKnownBranch());
    }

    public function testMinusOneOffsetCanPushAMemberBelowEveryBranch(): void
    {
        // Born 2020 → raw age 6 (baladins 1ère année), offset -1 → effective
        // age 5 → below every branch.
        $result = $this->service->getEffectiveAge(2020, -1, self::REFERENCE_YEAR);

        $this->assertSame(5, $result->age);
        $this->assertFalse($result->isInKnownBranch());
    }

    public function testNullBirthYearYieldsNullAgeAndNoBranch(): void
    {
        $result = $this->service->getEffectiveAge(null, 0, self::REFERENCE_YEAR);

        $this->assertNull($result->age);
        $this->assertNull($result->branchKey);
        $this->assertSame('', $result->getBranchYearLabel());
    }

    public function testExtractBirthYearHandlesBothDateFormats(): void
    {
        $this->assertSame(2018, MemberYearService::extractBirthYear('2018-05-12'));
        $this->assertSame(2018, MemberYearService::extractBirthYear('12/05/2018'));
        $this->assertNull(MemberYearService::extractBirthYear(null));
        $this->assertNull(MemberYearService::extractBirthYear(''));
        $this->assertNull(MemberYearService::extractBirthYear('inconnu'));
    }

    public function testReferenceYearFromScoutYearLabelIsTheStartYear(): void
    {
        $this->assertSame(2025, MemberYearService::referenceYearFromScoutYearLabel('2025-2026'));
        $this->assertSame(2023, MemberYearService::referenceYearFromScoutYearLabel('2023-2024'));
        $this->assertSame((int) date('Y'), MemberYearService::referenceYearFromScoutYearLabel('sans année'));
    }

    public function testColorForBranchSortOrderMatchesTheFourAnimesBranches(): void
    {
        $this->assertSame('#378ADD', MemberYearService::colorForBranchSortOrder(10));
        $this->assertSame('#639922', MemberYearService::colorForBranchSortOrder(20));
        $this->assertSame('#1D9E75', MemberYearService::colorForBranchSortOrder(30));
        $this->assertSame('#D85A30', MemberYearService::colorForBranchSortOrder(40));
    }

    public function testColorForBranchSortOrderFallsBackToNeutralForOtherBranches(): void
    {
        // Staff d'U (50), Route (60), Iama (70), unknown (99).
        $this->assertSame('#6c757d', MemberYearService::colorForBranchSortOrder(50));
        $this->assertSame('#6c757d', MemberYearService::colorForBranchSortOrder(99));
    }
}
