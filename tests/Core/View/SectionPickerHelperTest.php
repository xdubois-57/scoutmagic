<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\View\SectionPickerHelper;
use PHPUnit\Framework\TestCase;

class SectionPickerHelperTest extends TestCase
{
    public function testDefaultIsRequestedSectionWhenProvided(): void
    {
        $sections = [
            ['id' => 1, 'desk_code' => 'BAL'],
            ['id' => 2, 'desk_code' => 'LOU'],
            ['id' => 3, 'desk_code' => 'ECL'],
        ];

        $result = SectionPickerHelper::resolveDefault(2, [], $sections);
        $this->assertSame(2, $result);
    }

    public function testIgnoresRequestedSectionIfNotInAvailable(): void
    {
        $sections = [
            ['id' => 1, 'desk_code' => 'BAL'],
            ['id' => 2, 'desk_code' => 'LOU'],
        ];

        $result = SectionPickerHelper::resolveDefault(99, [], $sections);
        $this->assertSame(1, $result);
    }

    public function testDefaultIsHighestRoleMemberSection(): void
    {
        $sections = [
            ['id' => 1, 'desk_code' => 'BAL'],
            ['id' => 2, 'desk_code' => 'LOU'],
            ['id' => 3, 'desk_code' => 'ECL'],
        ];

        // Create two linked members: one intendant in BAL, one chief in ECL
        $intendantMember = $this->createMemberWithFunction('intendant', 'BAL');
        $chiefMember = $this->createMemberWithFunction('chief', 'ECL');

        $result = SectionPickerHelper::resolveDefault(null, [$intendantMember, $chiefMember], $sections);
        $this->assertSame(3, $result); // ECL (chief has higher role)
    }

    public function testDefaultIsFirstSectionWhenNoLinkedMembers(): void
    {
        $sections = [
            ['id' => 5, 'desk_code' => 'BAL'],
            ['id' => 6, 'desk_code' => 'LOU'],
        ];

        $result = SectionPickerHelper::resolveDefault(null, [], $sections);
        $this->assertSame(5, $result);
    }

    public function testReturnsNullWhenNoSectionsAvailable(): void
    {
        $result = SectionPickerHelper::resolveDefault(null, [], []);
        $this->assertNull($result);
    }

    public function testFallsBackToFirstWhenLinkedMemberHasNoSection(): void
    {
        $sections = [
            ['id' => 1, 'desk_code' => 'BAL'],
            ['id' => 2, 'desk_code' => 'LOU'],
        ];

        $member = $this->createMemberWithFunction('chief', null);

        $result = SectionPickerHelper::resolveDefault(null, [$member], $sections);
        $this->assertSame(1, $result);
    }

    private function createMemberWithFunction(string $role, ?string $sectionCode): MemberProfile
    {
        $fn = new MemberFunctionInfo(
            functionLabel: 'Animateur',
            functionRole: $role,
            branchName: 'Louveteaux',
            sectionName: $sectionCode ? "Section {$sectionCode}" : null,
            sectionCode: $sectionCode,
            isMainFunction: true,
            startDate: null,
            endDate: null
        );

        return new MemberProfile(
            memberYearId: random_int(1, 9999),
            memberId: random_int(1, 9999),
            deskId: 'DESK_' . random_int(1, 9999),
            firstName: 'Test',
            lastName: 'User',
            totem: 'Totem',
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [$fn],
            scoutYearLabel: '2025-2026'
        );
    }
}
