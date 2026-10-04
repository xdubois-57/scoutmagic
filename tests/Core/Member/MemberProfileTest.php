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

    /**
     * **Name AND totem, because a totem alone does not identify anybody.**
     *
     * The re-registration form showed `totem ?? firstName` — a parent with
     * two children in the same section could not tell which card was
     * whose, and a totem is precisely the name a family may never use at
     * home. The rule existed only as the `|display_name_full` Twig filter,
     * so a page that assembles its labels in PHP had no way to say it.
     */
    public function testGetDisplayNameFullNamesThePersonBehindTheTotem(): void
    {
        $this->assertSame('Baloo (John Doe)', $this->profile('John', 'Doe', 'Baloo')->getDisplayNameFull());
    }

    public function testGetDisplayNameFullIsJustTheNameWhenThereIsNoTotem(): void
    {
        $this->assertSame('John Doe', $this->profile('John', 'Doe', null)->getDisplayNameFull());
        $this->assertSame('John Doe', $this->profile('John', 'Doe', '')->getDisplayNameFull());
    }

    /**
     * A member with a totem and no civil name on file is not a reason to
     * render "Baloo ()".
     */
    public function testGetDisplayNameFullDoesNotRenderEmptyParentheses(): void
    {
        $this->assertSame('Baloo', $this->profile('', '', 'Baloo')->getDisplayNameFull());
    }

    /**
     * Both halves are normalized, as `|display_name_full` normalized them:
     * a roster import delivers "DOE" and "baloo" as readily as anything
     * else, and a card is not the place to shout.
     */
    public function testGetDisplayNameFullNormalizesWhatTheRosterDelivered(): void
    {
        $this->assertSame('Baloo (John Doe)', $this->profile('JOHN', 'DOE', 'BALOO')->getDisplayNameFull());
    }

    /**
     * Issue #722: the section totem joins the Desk totem, for the section
     * the page is about — « Guépard – Akela (Élie Wathelet) ».
     */
    public function testGetDisplayNameFullAddsTheTotemOfTheSectionTheCallerNames(): void
    {
        $profile = $this->profile('élie', 'wathelet', 'guépard', [3 => 'akela', 5 => 'Hathi']);

        $this->assertSame('Guépard – Akela (Élie Wathelet)', $profile->getDisplayNameFull(3));
        $this->assertSame('Guépard – Hathi (Élie Wathelet)', $profile->getDisplayNameFull(5));
        $this->assertSame('Guépard (Élie Wathelet)', $profile->getDisplayNameFull(9));
    }

    /** No Desk totem: the section totem stands alone in front of the name. */
    public function testGetDisplayNameFullShowsASectionTotemWithoutDeskTotem(): void
    {
        $this->assertSame('Akela (Élie Wathelet)', $this->profile('Élie', 'Wathelet', null, [3 => 'Akela'])->getDisplayNameFull(3));
    }

    /**
     * A page that does not know the section shows the section totem only
     * when there is a single one — with two, it cannot tell which applies.
     */
    public function testGetDisplayNameFullWithoutSectionUsesOnlyAnUnambiguousSectionTotem(): void
    {
        $this->assertSame('Guépard – Akela (Élie Wathelet)', $this->profile('Élie', 'Wathelet', 'Guépard', [3 => 'Akela'])->getDisplayNameFull());
        $this->assertSame('Guépard (Élie Wathelet)', $this->profile('Élie', 'Wathelet', 'Guépard', [3 => 'Akela', 5 => 'Hathi'])->getDisplayNameFull());
        $this->assertSame('Élie Wathelet', $this->profile('Élie', 'Wathelet', null, [])->getDisplayNameFull());
    }

    /**
     * @param array<int, string> $sectionTotems
     */
    private function profile(string $firstName, string $lastName, ?string $totem, array $sectionTotems = []): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'T001',
            firstName: $firstName,
            lastName: $lastName,
            totem: $totem,
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
            scoutYearLabel: '2025-2026',
            sectionTotems: $sectionTotems
        );
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
