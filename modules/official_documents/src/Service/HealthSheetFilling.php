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
 * **Nothing here answers a question the family left alone, and no text
 * decides an answer.** A square is crossed only where the sheet carries an
 * actual choice, and both are left blank otherwise. That is not caution for
 * its own sake — an empty « allergique ? » crossed NON is the site telling a
 * first-aider that a child has no allergies, on the strength of a parent not
 * having filled in a web form. The two squares left blank say « we do not
 * know », which is true and which a human reads correctly.
 *
 * Since issue #1346 that holds from the other side too: « allergique ? »,
 * « prend-elle un traitement ? » and the diet question have a choice of
 * their own (OUI / NON / non précisé), and a text written in the box under
 * it — « Aucune », « Aucun », « Non », or a real allergy — never turns it
 * into one. The choice is crossed as the family made it, NON included.
 *
 * **What the dependent boxes print follows the choice**, here and nowhere
 * else (`paragraphs()`), so the overflow warning, which reads what is
 * exported, and the download cannot disagree: OUI prints what was written,
 * NON prints « Sans objet » once in each box, and an unanswered choice
 * prints nothing at all — whatever the boxes still hold. What the family
 * typed is kept on the sheet in every case; « Sans objet » is computed for
 * the document and never stored.
 */
final class HealthSheetFilling
{
    /**
     * What a box prints when its question was answered NON (issue #1346).
     * Written by the document only — never a stored answer, so it cannot be
     * mistaken for something a parent said about a child's health.
     */
    public const NOT_APPLICABLE = 'Sans objet';

    /**
     * The identity lines the screen recaps, in the form's own words and order,
     * keyed by the `values()` entry each one reads.
     */
    public const IDENTITY_LABELS = [
        'member_last_name' => 'Nom :',
        'member_first_name' => 'Prénom :',
        'member_birth_date' => 'Date de naissance :',
        'member_street' => 'Rue :',
        'member_street_number' => 'N° :',
        'member_box' => 'Bte :',
        'member_postal_code' => 'Code postal :',
        'member_city' => 'Localité :',
        'member_phone' => 'Tél. / GSM :',
        'member_email' => 'Email :',
    ];

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
            'contact2_name' => $sheet->contact2Name,
            'contact2_relationship' => $sheet->contact2Relationship,
            'contact2_phone' => $sheet->contact2Phone,
            'contact2_email' => $sheet->contact2Email,
            'doctor_last_name' => $sheet->doctorLastName,
            'doctor_first_name' => $sheet->doctorFirstName,
            'doctor_phone' => $sheet->doctorPhone,
            'height' => $sheet->height,
            'weight' => $sheet->weight,
            'tetanus_last_booster' => $sheet->tetanusLastBooster,
        ];
    }

    /**
     * The identity as the document WILL print it, for the screen to recap in
     * read-only (issue #1346): the ten lines the site fills in itself, built
     * from `values()` so the recap and the paper cannot disagree — the legal
     * name rather than the totem, the first address that carries a street,
     * the mobile before the landline.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function identity(MemberProfile $member, HealthSheet $sheet): array
    {
        $values = self::values($member, $sheet);

        $lines = [];
        foreach (self::IDENTITY_LABELS as $key => $label) {
            $lines[] = ['label' => $label, 'value' => $values[$key]];
        }

        return $lines;
    }

    /**
     * The free-text answers as they are EXPORTED, keyed by the name the
     * screen and the storage give them — which is also the key
     * `Pdf\HealthSheetLayout::paragraphLines()` resolves to the form's
     * printed lines.
     *
     * « As exported » is the point (issue #1346): the allergies, their
     * consequences, the treatment and the diet follow the choice made above
     * them — OUI prints what was written, NON prints « Sans objet », an
     * unanswered choice prints nothing — so that the overflow warning, which
     * reads this, never complains about a text the paper will not carry. The
     * wrapping puts « Sans objet » on the first printed line of a box and
     * leaves the others empty.
     *
     * @return array<string, string>
     */
    public static function paragraphs(HealthSheet $sheet): array
    {
        return [
            // « Remarque » is a textarea on the screen: free text, so it is
            // fitted to its printed line here rather than drawn as a
            // single-line value. See `Pdf\HealthSheetLayout`.
            'contact1_note' => $sheet->contact1Note,
            'contact2_note' => $sheet->contact2Note,
            // The restrictions explain a NON to the activities, so they are
            // printed whatever was answered. The same goes for the list of
            // conditions below: an empty one is not « non » to each of them.
            'participation_details' => $sheet->participationDetails,
            'conditions_details' => $sheet->conditionsDetails,
            'illnesses_and_operations' => $sheet->illnessesAndOperations,
            'useful_information' => $sheet->usefulInformation,
            'allergies' => self::dependent($sheet->allergic, $sheet->allergies),
            'allergy_consequences' => self::dependent($sheet->allergic, $sheet->allergyConsequences),
            'diet' => self::dependent($sheet->followsDiet, $sheet->diet),
            'treatment' => self::dependent($sheet->takesTreatment, $sheet->treatment),
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
        // The two questions that have squares of their own (issue #1346):
        // crossed as the family answered, NON included, and never from a text.
        self::appendYesNo($ticks, $sheet->allergic, 'allergic_yes', 'allergic_no');
        self::appendYesNo($ticks, $sheet->takesTreatment, 'treatment_yes', 'treatment_no');
        // « Est-elle autonome… » only means something when a treatment is
        // declared: an answer kept from earlier is not carried to the paper
        // without one, and NON is never crossed on the family's behalf.
        if ($sheet->takesTreatment === 'yes') {
            self::appendYesNo($ticks, $sheet->treatmentAutonomy, 'autonomy_yes', 'autonomy_no');
        }

        if (isset(self::SWIMMING_TICKS[$sheet->swimmingLevel])) {
            $ticks[] = self::SWIMMING_TICKS[$sheet->swimmingLevel];
        }

        foreach (HealthSheet::CONDITIONS as $condition) {
            if ($sheet->conditions[$condition] ?? false) {
                $ticks[] = 'condition_' . $condition;
            }
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
     * What a box under an OUI / NON question prints: the family's text after
     * OUI, « Sans objet » after NON, nothing while the question is
     * unanswered — whatever the box still holds.
     */
    private static function dependent(string $answer, string $text): string
    {
        return match ($answer) {
            'yes' => $text,
            'no' => self::NOT_APPLICABLE,
            default => '',
        };
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
