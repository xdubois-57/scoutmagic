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
use Modules\OfficialDocuments\Service\HealthSheetFilling;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the health sheet says, as strings — no PDF anywhere in this file.
 *
 * The rule the whole suite is built around: **a square is crossed only
 * where the family answered.** An empty « allergique ? » crossed NON is the
 * site telling a first-aider that a child has no allergies on the strength
 * of a web form nobody filled in, and it is the kind of defect that is
 * invisible in a rendered document until somebody is standing in a hospital
 * with it.
 */
final class HealthSheetFillingTest extends TestCase
{
    /**
     * A member as the site knows them. Public because
     * `Tests\Modules\OfficialDocuments\Pdf\HealthSheetLayoutTest` asks this
     * class what the site writes, rather than keeping a second list of
     * field names that would drift from this one.
     *
     * @param list<MemberAddress> $addresses
     */
    public static function member(
        string $firstName = 'Loup',
        string $lastName = 'Dubois',
        ?string $birthDate = '2013-04-17',
        array $addresses = [],
        ?string $phone = '04 99 00 11 22',
        ?string $mobile = '0470 12 34 56',
        ?string $email = 'loup@example.be'
    ): MemberProfile {
        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
            firstName: $firstName,
            lastName: $lastName,
            totem: 'Akéla',
            quali: null,
            gender: null,
            birthDate: $birthDate,
            phone: $phone,
            mobile: $mobile,
            email: $email,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: $addresses,
            functions: [new MemberFunctionInfo('Animé', 'identified', 'Louveteaux', 'Meute', 'MEU', true, null, null)],
            scoutYearLabel: '2026-2027'
        );
    }

    private static function address(
        ?string $street = 'Rue de la Station',
        ?string $number = '148',
        ?string $box = 'B3'
    ): MemberAddress {
        return new MemberAddress('home', $street, $number, $box, null, '4000', 'Liège', 'Belgique');
    }

    // ---------------------------------------------------------------
    // Identity — the site's half of the form
    // ---------------------------------------------------------------

    /**
     * The legal name and never the totem. « Akéla » is not who an insurer
     * or a hospital is looking for, and this member has one precisely so
     * the test can fail if it leaks onto the form.
     */
    public function testTheFormCarriesTheLegalNameAndNotTheTotem(): void
    {
        $values = HealthSheetFilling::values(self::member(), HealthSheet::empty());

        $this->assertSame('Loup', $values['member_first_name']);
        $this->assertSame('Dubois', $values['member_last_name']);
        $this->assertNotContains('Akéla', $values);
    }

    /**
     * The form prints « Rue », « N° » and « Bte » on three separate runs of
     * dots, so the address is read in its parts rather than formatted into
     * one string and squeezed onto the first of them.
     */
    public function testTheAddressIsWrittenInThePartsTheFormPrints(): void
    {
        $values = HealthSheetFilling::values(
            self::member(addresses: [self::address()]),
            HealthSheet::empty()
        );

        $this->assertSame('Rue de la Station', $values['member_street']);
        $this->assertSame('148', $values['member_street_number']);
        $this->assertSame('B3', $values['member_box']);
        $this->assertSame('4000', $values['member_postal_code']);
        $this->assertSame('Liège', $values['member_city']);
    }

    /**
     * A member with several addresses on record gets the first that is
     * actually an address — a blank one ahead of it must not win and leave
     * the form empty.
     */
    public function testAnAddressWithoutAStreetIsSkipped(): void
    {
        $member = self::member(addresses: [
            new MemberAddress('post', null, null, null, null, null, null, null),
            self::address('Avenue des Tilleuls', '3', null),
        ]);

        $this->assertSame('Avenue des Tilleuls', HealthSheetFilling::postalAddressOf($member)?->street);
    }

    public function testAMemberWithNoAddressLeavesTheLinesBlank(): void
    {
        $values = HealthSheetFilling::values(self::member(), HealthSheet::empty());

        $this->assertNull(HealthSheetFilling::postalAddressOf(self::member()));
        $this->assertSame('', $values['member_street']);
        $this->assertSame('', $values['member_postal_code']);
    }

    /**
     * The mobile first: it is the number somebody rings from a campsite.
     */
    public function testTheMobileIsPreferredAndTheLandlineIsTheFallback(): void
    {
        $this->assertSame(
            '0470 12 34 56',
            HealthSheetFilling::values(self::member(), HealthSheet::empty())['member_phone']
        );
        $this->assertSame(
            '04 99 00 11 22',
            HealthSheetFilling::values(self::member(mobile: null), HealthSheet::empty())['member_phone']
        );
        $this->assertSame(
            '',
            HealthSheetFilling::values(self::member(phone: null, mobile: null), HealthSheet::empty())['member_phone']
        );
    }

    public function testTheBirthDateIsWrittenAsTheFormExpectsIt(): void
    {
        $this->assertSame(
            '17/04/2013',
            HealthSheetFilling::values(self::member(), HealthSheet::empty())['member_birth_date']
        );
    }

    /**
     * A birth date a Desk import left malformed leaves a blank on a printed
     * form and never takes the download down (SECURITY.md §35). A family
     * completing that one line by hand is a inconvenience; a member with no
     * document at all is not.
     */
    #[DataProvider('unusableDateProvider')]
    public function testAnUnusableBirthDateLeavesABlankRatherThanThrowing(?string $stored): void
    {
        $this->assertSame(
            '',
            HealthSheetFilling::values(self::member(birthDate: $stored), HealthSheet::empty())['member_birth_date']
        );
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unusableDateProvider(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'the zero date Desk exports' => ['0000-00-00'],
            'not a date at all' => ['inconnue'],
        ];
    }

    // ---------------------------------------------------------------
    // The family's half
    // ---------------------------------------------------------------

    public function testTheFamilysAnswersReachTheirOwnLines(): void
    {
        $sheet = HealthSheet::fromArray([
            'contact1_name' => 'Marie Dubois',
            'contact2_phone' => '0495 99 88 77',
            'doctor_last_name' => 'Dupont',
            'height' => '148 cm',
            'tetanus_last_booster' => '12/09/2023',
        ]);

        $values = HealthSheetFilling::values(self::member(), $sheet);

        $this->assertSame('Marie Dubois', $values['contact1_name']);
        $this->assertSame('0495 99 88 77', $values['contact2_phone']);
        $this->assertSame('Dupont', $values['doctor_last_name']);
        $this->assertSame('148 cm', $values['height']);
        $this->assertSame('12/09/2023', $values['tetanus_last_booster']);
    }

    /**
     * The free-text answers do NOT come out of `values()`: they run over
     * several printed lines and only the engine can say how much of one a
     * word takes, so they go through `paragraphs()` instead. A treatment
     * that appeared in both would be written twice.
     */
    public function testTheFreeTextAnswersAreNotAlsoSingleLineValues(): void
    {
        $sheet = HealthSheet::fromArray(['treatment' => 'Ventoline', 'allergies' => 'Arachides']);

        $values = HealthSheetFilling::values(self::member(), $sheet);

        $this->assertArrayNotHasKey('treatment', $values);
        $this->assertArrayNotHasKey('allergies', $values);
        $this->assertSame('Ventoline', HealthSheetFilling::paragraphs($sheet)['treatment']);
        $this->assertSame('Arachides', HealthSheetFilling::paragraphs($sheet)['allergies']);
    }

    // ---------------------------------------------------------------
    // The squares
    // ---------------------------------------------------------------

    public function testAnEmptySheetCrossesNothingAtAll(): void
    {
        $this->assertSame([], HealthSheetFilling::ticks(HealthSheet::empty()));
    }

    /**
     * **The heart of it.** Every OUI/NON question the family left alone
     * leaves both squares blank — « we do not know », which is true and
     * which a human reads correctly.
     */
    #[DataProvider('unansweredQuestionProvider')]
    public function testAQuestionLeftAloneCrossesNeitherSquare(string $key, string $yes, string $no): void
    {
        $ticks = HealthSheetFilling::ticks(HealthSheet::fromArray([$key => '']));

        $this->assertNotContains($yes, $ticks);
        $this->assertNotContains($no, $ticks, 'Le site répond « non » à la place de la famille.');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function unansweredQuestionProvider(): array
    {
        return [
            'participation' => ['participation', 'participation_yes', 'participation_no'],
            'tétanos' => ['tetanus_vaccinated', 'tetanus_yes', 'tetanus_no'],
            'autonomie' => ['treatment_autonomy', 'autonomy_yes', 'autonomy_no'],
            'allergies' => ['allergies', 'allergic_yes', 'allergic_no'],
            'traitement' => ['treatment', 'treatment_yes', 'treatment_no'],
        ];
    }

    #[DataProvider('answeredQuestionProvider')]
    public function testAnAnsweredQuestionCrossesTheSquareTheFamilyChose(
        string $key,
        string $answer,
        string $expected
    ): void {
        $this->assertContains($expected, HealthSheetFilling::ticks(HealthSheet::fromArray([$key => $answer])));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function answeredQuestionProvider(): array
    {
        return [
            'participation oui' => ['participation', 'yes', 'participation_yes'],
            'participation non' => ['participation', 'no', 'participation_no'],
            'tétanos oui' => ['tetanus_vaccinated', 'yes', 'tetanus_yes'],
            'tétanos non' => ['tetanus_vaccinated', 'no', 'tetanus_no'],
            'autonomie oui' => ['treatment_autonomy', 'yes', 'autonomy_yes'],
            'autonomie non' => ['treatment_autonomy', 'no', 'autonomy_no'],
        ];
    }

    /**
     * The two derived answers: writing an allergy down IS saying yes. And
     * never NON, because « the parent wrote nothing » is not « the child has
     * no allergies ».
     */
    public function testListingAnAllergyAnswersTheAllergyQuestion(): void
    {
        $ticks = HealthSheetFilling::ticks(HealthSheet::fromArray(['allergies' => 'Arachides']));

        $this->assertContains('allergic_yes', $ticks);
        $this->assertNotContains('allergic_no', $ticks);
    }

    public function testDescribingATreatmentAnswersTheTreatmentQuestion(): void
    {
        $ticks = HealthSheetFilling::ticks(HealthSheet::fromArray(['treatment' => 'Ventoline 100 µg']));

        $this->assertContains('treatment_yes', $ticks);
        $this->assertNotContains('treatment_no', $ticks);
    }

    #[DataProvider('swimmingLevelProvider')]
    public function testEachSwimmingLevelCrossesItsOwnSquare(string $level, string $expected): void
    {
        $ticks = HealthSheetFilling::ticks(HealthSheet::fromArray(['swimming_level' => $level]));

        $this->assertSame([$expected], $ticks);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function swimmingLevelProvider(): array
    {
        $cases = [];
        foreach (HealthSheetFilling::SWIMMING_TICKS as $level => $box) {
            $cases[$level] = [$level, $box];
        }

        return $cases;
    }

    /**
     * A level the form does not print crosses nothing — the closed
     * vocabulary `HealthSheet` enforces, seen from here.
     */
    public function testASwimmingLevelTheFormDoesNotPrintCrossesNothing(): void
    {
        $this->assertSame([], HealthSheetFilling::ticks(HealthSheet::fromArray(['swimming_level' => '25m'])));
    }

    /**
     * Each condition crosses its own square and only its own. A key out of
     * place here would cross « asthme » for a child who has « diabète »,
     * on a document nobody re-reads against the screen.
     */
    #[DataProvider('conditionProvider')]
    public function testEachConditionCrossesItsOwnSquare(string $condition): void
    {
        $ticks = HealthSheetFilling::ticks(HealthSheet::fromArray(['conditions' => [$condition => true]]));

        $this->assertSame(['condition_' . $condition], $ticks);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function conditionProvider(): array
    {
        $cases = [];
        foreach (HealthSheet::CONDITIONS as $condition) {
            $cases[$condition] = [$condition];
        }

        return $cases;
    }

    /**
     * And the whole sheet at once still crosses each square once: the
     * loops do not fall over each other.
     */
    public function testAFullySpecifiedSheetCrossesEverythingExactlyOnce(): void
    {
        $sheet = HealthSheet::fromArray([
            'participation' => 'yes',
            'tetanus_vaccinated' => 'no',
            'treatment_autonomy' => 'yes',
            'swimming_level' => 'good',
            'conditions' => array_fill_keys(HealthSheet::CONDITIONS, true),
            'allergies' => 'Arachides',
            'treatment' => 'Ventoline',
        ]);

        $ticks = HealthSheetFilling::ticks($sheet);

        $this->assertSame($ticks, array_unique($ticks));
        // Three answered questions, one swimming level, twelve conditions,
        // two derived answers.
        $this->assertCount(3 + 1 + count(HealthSheet::CONDITIONS) + 2, $ticks);
    }
}
