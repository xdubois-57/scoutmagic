<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Core\Member\MemberAddress;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Modules\OfficialDocuments\Service\ParentalAuthorizationFilling;
use Modules\OfficialDocuments\Service\ParentalAuthorizationInput;
use Modules\OfficialDocuments\Service\SignatoryCapacity;
use PHPUnit\Framework\TestCase;

/**
 * Every rule of the parental authorization, as a decision about strings.
 *
 * Deliberately not asserted against rendered PDF bytes: a test that read
 * them back would agree with whatever the code drew, while what actually
 * matters — which three mentions are cancelled, what happens to a member
 * who is in none of the four printed branches, whose name is written where
 * — is decidable here and would be invisible there.
 */
final class ParentalAuthorizationFillingTest extends TestCase
{
    private static function member(
        ?string $branch = 'Louveteaux',
        string $firstName = 'Loup',
        string $lastName = 'Dubois',
        ?string $totem = 'Akéla'
    ): MemberProfile {
        $functions = $branch === null ? [] : [
            new MemberFunctionInfo('Animé', 'identified', $branch, 'Meute', 'MEU', true, null, null),
        ];

        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
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
            functions: $functions,
            scoutYearLabel: '2026-2027'
        );
    }

    private static function leaderWith(string $street, string $postalCode, string $city): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 3,
            memberId: 3,
            deskId: 'D3',
            firstName: 'Camille',
            lastName: 'Renard',
            totem: 'Baloo',
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
            addresses: [new MemberAddress('main', $street, '8', null, null, $postalCode, $city, 'Belgique')],
            functions: [],
            scoutYearLabel: '2026-2027'
        );
    }

    private static function input(
        SignatoryCapacity $capacity = SignatoryCapacity::Father,
        bool $abroad = false
    ): ParentalAuthorizationInput {
        return ParentalAuthorizationInput::of(
            'Xavier Dubois',
            $capacity,
            new \DateTimeImmutable('2026-11-14'),
            new \DateTimeImmutable('2026-11-16'),
            'Verviers',
            $abroad
        );
    }

    // --- what is written ---

    /**
     * The federation's form names the person an insurer or a hospital is
     * looking for, which is never « Akéla ».
     */
    public function testTheMemberIsNamedByTheirLegalNameAndNeverTheirTotem(): void
    {
        $values = ParentalAuthorizationFilling::values(
            self::member(totem: 'Akéla'),
            null,
            'LgVI/25 — 25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertSame('Loup Dubois', $values['member_name']);
        $this->assertStringNotContainsString('Akéla', implode(' ', $values));
    }

    public function testTheDatesAreWrittenAsABelgianReaderWritesThem(): void
    {
        $values = ParentalAuthorizationFilling::values(
            self::member(),
            null,
            '25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertSame('14/11/2026', $values['start_date']);
        $this->assertSame('16/11/2026', $values['end_date']);
        $this->assertSame('20/09/2026', $values['today']);
    }

    /**
     * A section that has designated nobody leaves the form's own blank
     * lines, which a pen can fill — never the string « null » or a
     * half-written name.
     */
    public function testASectionWithNoResponsableLeavesThoseLinesBlank(): void
    {
        $values = ParentalAuthorizationFilling::values(
            self::member(),
            null,
            '25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertSame('', $values['leader_first_name']);
        $this->assertSame('', $values['leader_last_name']);
        $this->assertSame('', $values['leader_address']);
        $this->assertSame('', $values['leader_address_overflow']);
    }

    public function testAShortAddressStaysOnOneLine(): void
    {
        [$first, $second] = ParentalAuthorizationFilling::splitAddress(
            self::leaderWith('Rue des Écoles', '4800', 'Verviers')
        );

        $this->assertSame('Rue des Écoles 8, 4800 Verviers, Belgique', $first);
        $this->assertSame('', $second);
    }

    public function testALongAddressIsSplitOnACommaOverTheFormsTwoLines(): void
    {
        [$first, $second] = ParentalAuthorizationFilling::splitAddress(
            self::leaderWith('Avenue des Anciens Combattants de la Seconde Guerre mondiale', '1348', 'Ottignies')
        );

        $this->assertNotSame('', $second, 'an address too long must wrap onto the second line');
        $this->assertStringEndsNotWith(',', $first);
        $this->assertStringStartsNotWith(' ', $second);
        // Nothing lost between the two halves.
        $this->assertStringContainsString('Ottignies', $first . ' ' . $second);
        $this->assertStringContainsString('Avenue des Anciens', $first);
    }

    // --- what is crossed out ---

    public function testThreeOfTheFourCapacitiesAreStruckAndTheChosenOneStands(): void
    {
        foreach (SignatoryCapacity::all() as $chosen) {
            $strikes = ParentalAuthorizationFilling::strikes(self::member(), self::input($chosen));

            $this->assertNotContains($chosen->strikeZone(), $strikes, $chosen->value . ' must not be struck through');
            foreach (SignatoryCapacity::all() as $other) {
                if ($other !== $chosen) {
                    $this->assertContains($other->strikeZone(), $strikes, $other->value . ' doit être barré');
                }
            }
        }
    }

    public function testTheThreeBranchesTheMemberIsNotInAreStruck(): void
    {
        $strikes = ParentalAuthorizationFilling::strikes(self::member('Louveteaux'), self::input());

        $this->assertNotContains('branch_louveteaux', $strikes);
        $this->assertContains('branch_baladins', $strikes);
        $this->assertContains('branch_eclaireurs', $strikes);
        $this->assertContains('branch_pionniers', $strikes);
    }

    /**
     * The branch is read through `AgeBranchRepository::canonicalSortOrder()`,
     * so the accent, the case and a unit's own wording are one answer.
     */
    public function testTheBranchIsRecognisedHoweverTheUnitSpellsIt(): void
    {
        foreach (['Éclaireurs', 'eclaireurs', 'Éclaireurs (mixte)', 'ÉCLAIREURS'] as $spelling) {
            $this->assertSame(
                'branch_eclaireurs',
                ParentalAuthorizationFilling::branchStrikeFor(self::member($spelling)),
                "« {$spelling} » must be recognised as the Éclaireurs branch"
            );
        }
    }

    /**
     * The rule that is easy to get backwards: a member in none of the four
     * printed branches gets NOTHING struck. Striking all four would say
     * « none of these » on a form whose sentence needs one of them to stand.
     */
    public function testAMemberInNoneOfTheFourPrintedBranchesHasNoBranchStruck(): void
    {
        foreach (["Staff d'Unité", 'Iama', 'Route', 'Une branche inventée', null] as $branch) {
            $strikes = ParentalAuthorizationFilling::strikes(self::member($branch), self::input());

            foreach (ParentalAuthorizationFilling::BRANCH_STRIKE_BY_SORT_ORDER as $zone) {
                $this->assertNotContains(
                    $zone,
                    $strikes,
                    sprintf('« %s » ne doit rien barrer du tout', $branch ?? 'aucune fonction')
                );
            }
        }
    }

    /**
     * Note (1) of the form: the sentence about leaving Belgian territory is
     * crossed out for activities in Belgium. That is the ordinary case, so
     * it is the default — and both printed lines of the sentence are struck,
     * never just the first.
     */
    public function testTheOutOfBelgiumSentenceIsStruckByDefaultOnBothOfItsLines(): void
    {
        $strikes = ParentalAuthorizationFilling::strikes(self::member(), self::input(abroad: false));

        $this->assertContains('abroad_line_1', $strikes);
        $this->assertContains('abroad_line_2', $strikes);
    }

    public function testTheOutOfBelgiumSentenceStandsWhenTheActivityLeavesTheCountry(): void
    {
        $strikes = ParentalAuthorizationFilling::strikes(self::member(), self::input(abroad: true));

        $this->assertNotContains('abroad_line_1', $strikes);
        $this->assertNotContains('abroad_line_2', $strikes);
    }
}
