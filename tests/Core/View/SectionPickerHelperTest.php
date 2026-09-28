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

    /**
     * **resolveMainSection() is resolveDefault() MINUS its fallback**, and
     * that difference is a permission (issue #650): the fallback names a
     * section the reader has nothing to do with, which is fine for deciding
     * which tab opens and a data leak for deciding who sees children's
     * names.
     *
     * Every case below is one where resolveDefault() answers with a section
     * anyway — asserted side by side, so the two can never quietly converge.
     */
    public function testMainSectionRefusesTheFallbackThatResolveDefaultTakes(): void
    {
        $sections = [
            ['id' => 5, 'desk_code' => 'BAL'],
            ['id' => 6, 'desk_code' => 'LOU'],
        ];

        foreach ([
            'an account linked to no member' => [],
            'a member whose main function names no section' => [$this->createMemberWithFunction('chief', null)],
            'a member in a section the caller did not offer' => [$this->createMemberWithFunction('chief', 'ECL')],
        ] as $case => $linkedMembers) {
            $this->assertNull(
                SectionPickerHelper::resolveMainSection($linkedMembers, $sections),
                'a section was named for ' . $case . ', which would grant its staff access'
            );
            $this->assertSame(
                5,
                SectionPickerHelper::resolveDefault(null, $linkedMembers, $sections),
                'resolveDefault() stopped falling back for ' . $case . ', which is what the display relies on'
            );
        }
    }

    public function testMainSectionIsTheHighestRoleMembersOwnSection(): void
    {
        $sections = [
            ['id' => 1, 'desk_code' => 'BAL'],
            ['id' => 2, 'desk_code' => 'LOU'],
            ['id' => 3, 'desk_code' => 'ECL'],
        ];

        $this->assertSame(
            3,
            SectionPickerHelper::resolveMainSection(
                [$this->createMemberWithFunction('intendant', 'BAL'), $this->createMemberWithFunction('chief', 'ECL')],
                $sections
            ),
            'the section came from a member other than the highest-role one'
        );
    }

    public function testMainSectionIsNullWithoutAnySection(): void
    {
        $this->assertNull(
            SectionPickerHelper::resolveMainSection([$this->createMemberWithFunction('chief', 'BAL')], [])
        );
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
