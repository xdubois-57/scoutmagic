<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\SectionContactAccess;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * Whose contacts a page of the chiefs' area shows (issue #1116): every
 * section from `chief` up, only the linked members' sections below.
 */
class SectionContactAccessTest extends TestCase
{
    /**
     * A linked member holding a function in each of $sectionCodes (null:
     * a function outside any section).
     */
    public static function linkedMemberIn(?string ...$sectionCodes): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'D1',
            firstName: 'Ines',
            lastName: 'Dupont',
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
            functions: array_map(
                static fn(?string $code): MemberFunctionInfo => new MemberFunctionInfo(
                    'Fonction',
                    'identified',
                    null,
                    null,
                    $code,
                    true,
                    null,
                    null
                ),
                $sectionCodes
            ),
            scoutYearLabel: '2025-2026'
        );
    }

    public function testAnIntendantSeesOnlyTheSectionsOfTheirLinkedMembers(): void
    {
        $access = SectionContactAccess::forViewer(
            Role::INTENDANT,
            [self::linkedMemberIn('LOU01'), self::linkedMemberIn('BAL01', null)]
        );

        $this->assertTrue($access->seesContactsOf('LOU01'));
        $this->assertTrue($access->seesContactsOf('BAL01'));
        $this->assertFalse($access->seesContactsOf('LOU02'));
        $this->assertFalse($access->seesContactsOf(null));
        $this->assertFalse($access->seesEverySection());
    }

    public function testAnIntendantLinkedToNobodySeesNoSection(): void
    {
        $access = SectionContactAccess::forViewer(Role::INTENDANT, []);

        $this->assertFalse($access->seesContactsOf('LOU01'));
    }

    public function testFromChiefUpEverySectionIsSeenWhateverTheLinks(): void
    {
        foreach ([Role::CHIEF, Role::ADMIN, Role::SUPERADMIN] as $role) {
            $access = SectionContactAccess::forViewer($role, []);

            $this->assertTrue($access->seesEverySection(), $role->value);
            $this->assertTrue($access->seesContactsOf('LOU02'), $role->value);
            $this->assertTrue($access->seesContactsOf(null), $role->value);
        }
    }

    public function testANumericDeskCodeIsMatchedAsTheStringItIs(): void
    {
        $access = SectionContactAccess::forViewer(Role::INTENDANT, [self::linkedMemberIn('1234')]);

        $this->assertSame(['1234'], SectionContactAccess::linkedSectionCodes([self::linkedMemberIn('1234')]));
        $this->assertTrue($access->seesContactsOf('1234'));
        $this->assertFalse($access->seesContactsOf('123'));
    }

    public function testTheLinkedSectionCodesAreListedOnce(): void
    {
        $this->assertSame(
            ['LOU01', 'BAL01'],
            SectionContactAccess::linkedSectionCodes([
                self::linkedMemberIn('LOU01', 'BAL01'),
                self::linkedMemberIn('LOU01'),
            ])
        );
    }

    /**
     * The two pages of the chiefs' area that show a section's people ask
     * this one rule — the divergence #1116 was about cannot come back by
     * one of them growing its own check again.
     */
    public function testStaffsAndTheSectionRosterBothAskThisRule(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['StaffsController', 'SectionRosterController'] as $controller) {
            $source = (string) file_get_contents($root . '/core/Http/Controller/' . $controller . '.php');
            $this->assertStringContainsString('SectionContactAccess::forViewer(', $source, $controller);
        }
    }
}
