<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Member\MemberAddress;
use Core\Member\MemberProfile;
use Core\Service\DateInput;
use Modules\OfficialDocuments\Value\HealthSheet;

/**
 * What goes on the health sheet and which squares get a cross — as a
 * decision, with no PDF anywhere near it.
 *
 * Same seam as `ParentalAuthorizationFilling`, and for the same reason:
 * every interesting rule of this document is a rule about strings, and a
 * test that read them back out of rendered PDF bytes would agree with
 * whatever the code happened to do.
 *
 * ---------------------------------------------------------------------
 * THE ONE RULE WORTH STATING OUT LOUD
 * ---------------------------------------------------------------------
 *
 * **Nothing here answers a question the family left alone.** The form has
 * eight pairs of OUI/NON squares; this class crosses one only where the
 * sheet carries an actual answer, and leaves both blank otherwise. That is
 * not caution for its own sake — an empty « allergique ? » crossed NON is
 * the site telling a first-aider that a child has no allergies, on the
 * strength of a parent not having filled in a web form. The two squares
 * left blank say « we do not know », which is true and which a human reads
 * correctly.
 *
 * The two derived pairs follow the same rule from the other side:
 * « allergique ? » is crossed OUI when the family listed an allergy and
 * « prend-elle un traitement ? » OUI when they described one, because
 * writing one down IS the answer. Neither is ever crossed NON.
 */
final class HealthSheetFilling
{
    /**
     * The swimming answers, keyed by the vocabulary
     * `Value\HealthSheet::SWIMMING_LEVELS` uses and valued by the square
     * `Pdf\HealthSheetLayout::tickBoxes()` prints.
     */
    public const SWIMMING_TICKS = [
        'very_good' => 'swimming_very_good',
        'good' => 'swimming_good',
        'fair' => 'swimming_fair',
        'poor' => 'swimming_poor',
        'not_at_all' => 'swimming_not_at_all',
    ];

    /**
     * Every single-line value the site writes, keyed by the layout's field
     * names.
     *
     * The free-text answers are NOT here: they run over several printed
     * lines and only the engine knows how much of one a word takes, so they
     * go through `paragraphs()` and `OverlayPdf::wrapInto()`.
     *
     * @return array<string, string>
     */
    public static function values(MemberProfile $member, HealthSheet $sheet): array
    {
        $address = self::postalAddressOf($member);

        return [
            // --- Identity, from the membership record and never from the
            // form: this module neither stores nor lets anybody edit it
            // (specifications.md §44). The legal name, never the totem —
            // « Akéla » is not who a hospital is looking for.
            'member_last_name' => $member->lastName,
            'member_first_name' => $member->firstName,
            'member_birth_date' => self::date($member->birthDate),
            'member_street' => $address->street ?? '',
            'member_street_number' => $address->number ?? '',
            'member_box' => $address->box ?? '',
            'member_postal_code' => $address->postalCode ?? '',
            'member_city' => $address->city ?? '',
            // The mobile first: it is the number somebody rings from a
            // campsite, and the landline is the fallback.
            'member_phone' => trim($member->mobile ?? '') !== ''
                ? (string) $member->mobile
                : (string) ($member->phone ?? ''),
            'member_email' => (string) ($member->email ?? ''),

            // --- The family's own answers ---
            'contact1_name' => $sheet->contact1Name,
            'contact1_relationship' => $sheet->contact1Relationship,
            'contact1_phone' => $sheet->contact1Phone,
            'contact1_email' => $sheet->contact1Email,
            'contact1_note' => $sheet->contact1Note,
            'contact2_name' => $sheet->contact2Name,
            'contact2_relationship' => $sheet->contact2Relationship,
            'contact2_phone' => $sheet->contact2Phone,
            'contact2_email' => $sheet->contact2Email,
            'contact2_note' => $sheet->contact2Note,
            'doctor_last_name' => $sheet->doctorLastName,
            'doctor_first_name' => $sheet->doctorFirstName,
            'doctor_phone' => $sheet->doctorPhone,
            'height' => $sheet->height,
            'weight' => $sheet->weight,
            'tetanus_last_booster' => $sheet->tetanusLastBooster,
        ];
    }

    /**
     * The free-text answers, keyed by the name the screen and the storage
     * give them — which is also the key `Pdf\HealthSheetLayout::
     * paragraphLines()` resolves to the form's printed lines.
     *
     * @return array<string, string>
     */
    public static function paragraphs(HealthSheet $sheet): array
    {
        return [
            'participation_details' => $sheet->participationDetails,
            'conditions_details' => $sheet->conditionsDetails,
            'illnesses_and_operations' => $sheet->illnessesAndOperations,
            'useful_information' => $sheet->usefulInformation,
            'allergies' => $sheet->allergies,
            'allergy_consequences' => $sheet->allergyConsequences,
            'diet' => $sheet->diet,
            'treatment' => $sheet->treatment,
        ];
    }

    /**
     * Every square that gets a cross.
     *
     * @return list<string>
     */
    public static function ticks(HealthSheet $sheet): array
    {
        $ticks = [];

        self::appendYesNo($ticks, $sheet->participation, 'participation_yes', 'participation_no');
        self::appendYesNo($ticks, $sheet->tetanusVaccinated, 'tetanus_yes', 'tetanus_no');
        self::appendYesNo($ticks, $sheet->treatmentAutonomy, 'autonomy_yes', 'autonomy_no');

        if (isset(self::SWIMMING_TICKS[$sheet->swimmingLevel])) {
            $ticks[] = self::SWIMMING_TICKS[$sheet->swimmingLevel];
        }

        foreach (HealthSheet::CONDITIONS as $condition) {
            if ($sheet->conditions[$condition] ?? false) {
                $ticks[] = 'condition_' . $condition;
            }
        }

        // The two derived answers. OUI when the family wrote something,
        // nothing at all otherwise — never NON, see the class docblock.
        if ($sheet->allergies !== '') {
            $ticks[] = 'allergic_yes';
        }
        if ($sheet->treatment !== '') {
            $ticks[] = 'treatment_yes';
        }

        return $ticks;
    }

    /**
     * The member's postal address, or null when they have none on record.
     *
     * The first one that carries a street: `MemberProfile` may hold several
     * (a second parent, a correspondence address), and the form has room
     * for one. Reading the parts separately rather than through
     * `MemberAddress::format()` because this form prints « Rue », « N° » and
     * « Bte » on three runs of dots of its own.
     */
    public static function postalAddressOf(MemberProfile $member): ?MemberAddress
    {
        foreach ($member->addresses as $address) {
            if (trim($address->street ?? '') !== '') {
                return $address;
            }
        }

        return null;
    }

    /**
     * A stored date as the form wants it, and an empty line for anything
     * that is not one.
     *
     * `DateInput::fromStorage()` rather than a constructor: a birth date
     * that a Desk import left malformed must leave a blank on a printed
     * form, never take the download down (SECURITY.md §35).
     */
    private static function date(?string $stored): string
    {
        return DateInput::fromStorage($stored)?->format('d/m/Y') ?? '';
    }

    /**
     * @param list<string> $ticks
     */
    private static function appendYesNo(array &$ticks, string $answer, string $yes, string $no): void
    {
        if ($answer === 'yes') {
            $ticks[] = $yes;
        } elseif ($answer === 'no') {
            $ticks[] = $no;
        }
    }
}
