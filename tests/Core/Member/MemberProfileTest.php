<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberAddress;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use PHPUnit\Framework\TestCase;

class MemberProfileTest extends TestCase
{
    public function testGetDisplayNameReturnsTotemWhenAvailable(): void
    {
        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: 'Baloo',
            quali: 'Joyeux',
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
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $this->assertSame('Baloo', $profile->getDisplayName());
    }

    public function testGetDisplayNameReturnsFirstNameWhenNoTotem(): void
    {
        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $this->assertSame('John', $profile->getDisplayName());
    }

    public function testGetMainFunctionReturnsTheFunctionMarkedAsMain(): void
    {
        $functions = [
            new MemberFunctionInfo(
                functionLabel: 'Animateur',
                functionRole: 'identified',
                branchName: 'Louveteaux',
                sectionName: 'Meute Akela',
                sectionCode: 'L1',
                isMainFunction: false,
                startDate: '2025-09-01',
                endDate: null
            ),
            new MemberFunctionInfo(
                functionLabel: 'Chef',
                functionRole: 'chief',
                branchName: 'Louveteaux',
                sectionName: 'Meute Akela',
                sectionCode: 'L1',
                isMainFunction: true,
                startDate: '2025-09-01',
                endDate: null
            ),
        ];

        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: $functions,
            scoutYearLabel: '2025-2026'
        );

        $mainFunction = $profile->getMainFunction();
        $this->assertNotNull($mainFunction);
        $this->assertSame('Chef', $mainFunction->functionLabel);
        $this->assertTrue($mainFunction->isMainFunction);
    }

    public function testGetMainFunctionReturnsFirstFunctionWhenNoneMarkedAsMain(): void
    {
        $functions = [
            new MemberFunctionInfo(
                functionLabel: 'Animateur',
                functionRole: 'identified',
                branchName: 'Louveteaux',
                sectionName: 'Meute Akela',
                sectionCode: 'L1',
                isMainFunction: false,
                startDate: '2025-09-01',
                endDate: null
            ),
            new MemberFunctionInfo(
                functionLabel: 'Intendant',
                functionRole: 'intendant',
                branchName: null,
                sectionName: null,
                sectionCode: null,
                isMainFunction: false,
                startDate: '2025-09-01',
                endDate: null
            ),
        ];

        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: $functions,
            scoutYearLabel: '2025-2026'
        );

        $mainFunction = $profile->getMainFunction();
        $this->assertNotNull($mainFunction);
        $this->assertSame('Animateur', $mainFunction->functionLabel);
    }

    public function testGetMainFunctionReturnsNullWhenNoFunctions(): void
    {
        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $this->assertNull($profile->getMainFunction());
    }

    public function testGetMainSectionNameReturnsSectionNameFromMainFunction(): void
    {
        $functions = [
            new MemberFunctionInfo(
                functionLabel: 'Animateur',
                functionRole: 'identified',
                branchName: 'Louveteaux',
                sectionName: 'Meute Akela',
                sectionCode: 'L1',
                isMainFunction: true,
                startDate: '2025-09-01',
                endDate: null
            ),
        ];

        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: $functions,
            scoutYearLabel: '2025-2026'
        );

        $this->assertSame('Meute Akela', $profile->getMainSectionName());
    }

    public function testGetMainSectionNameReturnsNullWhenNoFunctions(): void
    {
        $profile = new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: 'John',
            lastName: 'Doe',
            totem: null,
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
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $this->assertNull($profile->getMainSectionName());
    }
}
