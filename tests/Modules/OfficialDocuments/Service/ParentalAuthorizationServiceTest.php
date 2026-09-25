<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberAddress;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Module\HookRegistry;
use Core\Module\SectionResponsableProvider;
use Core\Security\EncryptionService;
use Modules\OfficialDocuments\Service\ParentalAuthorizationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Everything the authorization needs that the parent does not type.
 *
 * The controller test doubles this class on purpose — it is testing an
 * access boundary, not a lookup — so this is where the four questions it
 * answers are actually asked. Two of them have a wrong answer that would
 * never show up as an error: a responsable whose address is missing (the
 * form then prints a name with no address under it) and a unit line built
 * from a setting nobody filled in.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ParentalAuthorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MemberService $memberService;
    private SectionService $sectionService;
    private SettingService $settings;
    private int $scoutYearId;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $this->encryption)
);
        $this->sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('2026-2027', '2026-09-01', '2027-08-31', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('MEU', {$branchId}, 'Meute')"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function service(?SectionResponsableProvider $responsables = null): ParentalAuthorizationService
    {
        $hooks = new HookRegistry();
        if ($responsables !== null) {
            $hooks->register(SectionResponsableProvider::class, $responsables);
        }

        return new ParentalAuthorizationService(
            $this->memberService,
            $this->sectionService,
            $this->settings,
            $hooks
        );
    }

    private static function member(?string $sectionCode = 'MEU', array $addresses = []): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
            firstName: 'Loup',
            lastName: 'Dubois',
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
            addresses: $addresses,
            functions: $sectionCode === null
                ? []
                : [new MemberFunctionInfo('Animé', 'identified', 'Louveteaux', 'Meute', $sectionCode, true, null, null)],
            scoutYearLabel: '2026-2027'
        );
    }

    // --- sectionIdFor() ---

    public function testASectionIsFoundByTheCodeAMembersFunctionCarries(): void
    {
        $this->assertSame($this->sectionId, $this->service()->sectionIdFor('MEU'));
    }

    /**
     * Null rather than an exception: the event picker reads it as « no
     * section to narrow to » and offers the unit's events, which is a better
     * screen than an error page.
     */
    public function testAnUnknownSectionCodeIsNullRatherThanAnError(): void
    {
        $this->assertNull($this->service()->sectionIdFor('INEXISTANT'));
    }

    // --- unitLabel() ---

    /**
     * Register a setting the way `ModuleManager::registerModule()` does —
     * **under the module's own id**.
     *
     * This helper exists because its absence hid a real defect for three
     * iterations (issue #433). The tests below used to register the unit
     * code with no module id at all, which files it under `_core_`; the
     * service then read it back with no module id either, and the two
     * halves of the same mistake cancelled out. Three green tests over a
     * line that, in production, never once returned the code a chief had
     * configured.
     *
     * So: a module setting is registered here exactly as the application
     * registers it, and if a call site forgets the scope the test goes red
     * instead of agreeing with it.
     */
    private function registerModuleSetting(string $key, string $value): void
    {
        $this->settings->register(
            $key,
            $value,
            'text',
            'x',
            'x',
            ParentalAuthorizationService::MODULE_ID
        );
    }

    public function testTheUnitLineCarriesTheFederationsCodeBesideTheName(): void
    {
        $this->settings->register('site_name', '25e SV', 'text', 'x', 'x');
        $this->registerModuleSetting(ParentalAuthorizationService::UNIT_CODE_SETTING, 'LgVI/25');

        $this->assertSame('LgVI/25 — 25e SV', $this->service()->unitLabel());
    }

    /**
     * The regression itself, said as a property rather than as a setup
     * detail: a code stored in the `_core_` scope — where nothing puts it —
     * must NOT be found. Without this, a call site that dropped the scope
     * again would still pass the test above the day somebody "simplified"
     * the helper.
     */
    public function testACodeFiledOutsideTheModulesScopeIsNotPickedUp(): void
    {
        $this->settings->register('site_name', '25e SV', 'text', 'x', 'x');
        // No module id: the shape the old tests used, and the shape that
        // made them agree with a broken call site.
        $this->settings->register(ParentalAuthorizationService::UNIT_CODE_SETTING, 'LgVI/25', 'text', 'x', 'x');

        $this->assertSame('25e SV', $this->service()->unitLabel());
    }

    /**
     * The ordinary case on a fresh install: nobody has filled the code in.
     * The line then carries the unit's name alone rather than a stray dash
     * or an empty « — » on an official form.
     */
    public function testAnEmptyCodeLeavesTheNameAlone(): void
    {
        $this->settings->register('site_name', '25e SV', 'text', 'x', 'x');
        $this->registerModuleSetting(ParentalAuthorizationService::UNIT_CODE_SETTING, '');

        $this->assertSame('25e SV', $this->service()->unitLabel());
    }

    public function testAUnitWithNeitherIsAnEmptyLineAndNotADash(): void
    {
        $this->settings->register('site_name', '', 'text', 'x', 'x');
        $this->registerModuleSetting(ParentalAuthorizationService::UNIT_CODE_SETTING, '');

        $this->assertSame('', $this->service()->unitLabel());
    }

    // --- defaultPlaceFor() ---

    public function testTheSigningPlaceDefaultsToTheMembersOwnCity(): void
    {
        $member = self::member(addresses: [
            new MemberAddress('main', 'Rue des Écoles', '8', null, null, '4800', 'Verviers', 'Belgique'),
        ]);

        $this->assertSame('Verviers', $this->service()->defaultPlaceFor($member));
    }

    /**
     * An address row with no city is not the same as no address, and the
     * first one that actually carries a city is the one to propose — a
     * member can have several.
     */
    public function testAnAddressWithNoCityIsSkippedRatherThanProposedEmpty(): void
    {
        $member = self::member(addresses: [
            new MemberAddress('main', 'Rue sans ville', '1', null, null, '', '', 'Belgique'),
            new MemberAddress('second', 'Rue des Écoles', '8', null, null, '4800', 'Verviers', 'Belgique'),
        ]);

        $this->assertSame('Verviers', $this->service()->defaultPlaceFor($member));
    }

    public function testAMemberWithNoAddressProposesNothing(): void
    {
        $this->assertSame('', $this->service()->defaultPlaceFor(self::member()));
    }

    // --- responsableFor() ---

    /**
     * The whole reason this method makes two calls instead of one: the hook
     * answers from `hydrateMemberProfile()`, which does NOT load addresses
     * (ARCHITECTURE.md §8.22), while the form asks for the responsable's
     * « Adresse complète ». A version that trusted the hook's profile would
     * print a name with a blank address under it, on every document, and
     * nothing would raise a thing.
     */
    public function testTheResponsableComesBackWithTheirAddressLoaded(): void
    {
        $leaderYearId = $this->createLeaderWithAddress();
        $lead = $this->memberService->getMemberProfile($leaderYearId);

        // The hook answers the address-less profile, exactly as the
        // trombinoscope's own provider does.
        $responsable = $this->service($this->responsableProviderAnswering(
            new MemberProfile(
                memberYearId: $lead->memberYearId,
                memberId: $lead->memberId,
                deskId: $lead->deskId,
                firstName: $lead->firstName,
                lastName: $lead->lastName,
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
                scoutYearLabel: '2026-2027'
            )
        ))->responsableFor(self::member(), $this->scoutYearId);

        $this->assertNotNull($responsable);
        $this->assertNotSame([], $responsable->addresses, 'the address must have been reloaded');
    }

    /**
     * Three ways there is nobody, and all three leave the form's own blank
     * lines for a pen rather than raising anything: no trombinoscope module,
     * no designated responsable, no section at all.
     */
    public function testNoResponsableIsNullAndNeverAnError(): void
    {
        $this->assertNull(
            $this->service()->responsableFor(self::member(), $this->scoutYearId),
            'module trombinoscope absent'
        );
        $this->assertNull(
            $this->service($this->responsableProviderAnswering(null))
                ->responsableFor(self::member(), $this->scoutYearId),
            'section sans responsable désigné'
        );
        $this->assertNull(
            $this->service($this->responsableProviderAnswering(null))
                ->responsableFor(self::member(sectionCode: null), $this->scoutYearId),
            'membre sans section'
        );
    }

    public function testASectionCodeThisInstallationDoesNotKnowAnswersNobody(): void
    {
        $this->assertNull(
            $this->service($this->responsableProviderAnswering(null))
                ->responsableFor(self::member(sectionCode: 'INEXISTANT'), $this->scoutYearId)
        );
    }

    private function responsableProviderAnswering(?MemberProfile $lead): SectionResponsableProvider
    {
        // A stub, not a hand-written double: the interface carries a second
        // method this module never calls, and spelling it out here would be
        // a body nobody reads that goes stale the day it changes.
        $provider = $this->createStub(SectionResponsableProvider::class);
        $provider->method('getResponsable')->willReturn($lead);

        return $provider;
    }

    private function createLeaderWithAddress(): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D3')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Camille', 'member_years.first_name'),
            $this->encryption->encrypt('Renard', 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted,
                                           postal_code_encrypted, city_encrypted, country_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'main',
            $this->encryption->encrypt('Rue des Écoles', 'member_addresses.street'),
            $this->encryption->encrypt('8', 'member_addresses.number'),
            $this->encryption->encrypt('4800', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Verviers', 'member_addresses.city'),
            $this->encryption->encrypt('Belgique', 'member_addresses.country'),
        ]);

        return $memberYearId;
    }
}
