<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * Every position on `templates/fiche-sante.pdf`, over its two pages, and
 * nothing else.
 *
 * Same contract as `ParentalAuthorizationLayout` — millimetres from the
 * top-left corner, a `TextField`'s Y is the BASELINE of the printed dotted
 * line, a `TickBox`'s X/Y is its top-left corner — and the same reason for
 * existing: coordinates scattered through a service are coordinates nobody
 * can re-align when the federation moves a margin.
 *
 * ---------------------------------------------------------------------
 * HOW THESE NUMBERS WERE OBTAINED — read this before changing one
 * ---------------------------------------------------------------------
 *
 * Not by eye and not from the grid script, which is a check rather than a
 * source. The template was rendered at 300 dpi **after FPDI had flattened
 * it**, and every dotted line was found by classifying each pixel column of
 * a baseline's band: ink in the letter body above the baseline means a
 * printed label, ink only where a full stop lives means a dot. The first
 * and last dot of each run are what `$x` and `$x + $width` are derived
 * from, with a millimetre of air at each end so a value never touches the
 * label it follows.
 *
 * Flattening the template first is the part that is easy to skip and
 * expensive to get wrong: a square that turned out to be a form widget
 * rather than drawn content would vanish on import, and every cross this
 * map places would land on blank paper. They are drawn content — verified,
 * not assumed (see `TickBox`).
 *
 * So: when the federation republishes this form, re-run that measurement
 * rather than nudging numbers. `TemplateLibrary`'s docblock has the rest of
 * the procedure.
 *
 * ---------------------------------------------------------------------
 * WHAT THIS MAP DOES NOT DECIDE
 * ---------------------------------------------------------------------
 *
 * Which box gets a cross and what goes on which line is
 * `Service\HealthSheetFilling`. This file only says where. A name here is
 * a place on paper, and the same name in the filling class is an answer —
 * that is the whole seam.
 */
final class HealthSheetLayout
{
    /** The template's own pages, both A4 portrait. */
    public const PAGE_COUNT = 2;
    public const PAGE_WIDTH_MM = 210.0;
    public const PAGE_HEIGHT_MM = 297.0;

    /**
     * Every printed line the site writes on, keyed by the name the filling
     * class uses.
     *
     * The `_1`, `_2`, `_3` suffixes are the form's own continuation lines,
     * in reading order — see `paragraphLines()`.
     *
     * @return array<string, TextField>
     */
    public static function textFields(): array
    {
        return [
            // ---------- Page 1 : « Identité de la personne » ----------
            // « Nom : ……… Prénom : ……… » — one line, two runs of dots, so
            // the family name and the first name each get their own rather
            // than being written as one string over both.
            'member_last_name' => new TextField(30.6, 72.0, 66.7),
            'member_first_name' => new TextField(114.9, 72.0, 72.3),
            'member_birth_date' => new TextField(50.9, 78.5, 136.3),
            // « Rue : ……… N° : ……… Bte : ……… » — `Core\Member\MemberAddress`
            // holds exactly these three separately, so nothing has to be
            // taken apart to fill them.
            'member_street' => new TextField(30.0, 84.9, 92.4),
            'member_street_number' => new TextField(132.1, 84.9, 19.1),
            'member_box' => new TextField(162.4, 84.9, 24.8),
            'member_postal_code' => new TextField(41.5, 91.3, 32.8),
            'member_city' => new TextField(92.5, 91.3, 94.7),
            'member_phone' => new TextField(38.6, 97.7, 148.6),
            'member_email' => new TextField(32.2, 104.1, 154.0),

            // ---------- Page 1 : « Personnes à contacter en cas d'urgence »
            // A two-column table with a printed frame and no dotted lines:
            // the value goes after the label and stops at the frame
            // (102.4 for the left column, 189.9 for the right), not at
            // LINE_END.
            'contact1_name' => new TextField(47.5, 117.8, 53.5),
            'contact1_relationship' => new TextField(47.6, 124.7, 53.4),
            'contact1_phone' => new TextField(39.5, 131.5, 61.5),
            'contact1_email' => new TextField(32.9, 138.4, 68.1),
            'contact1_note' => new TextField(40.0, 145.2, 61.0),
            'contact2_name' => new TextField(129.8, 117.8, 58.7),
            'contact2_relationship' => new TextField(129.9, 124.7, 58.6),
            'contact2_phone' => new TextField(121.8, 131.5, 66.7),
            'contact2_email' => new TextField(115.2, 138.4, 73.3),
            'contact2_note' => new TextField(122.3, 145.2, 66.2),

            // ---------- Page 1 : « Coordonnées du médecin traitant » ----
            'doctor_last_name' => new TextField(30.6, 161.8, 66.7),
            'doctor_first_name' => new TextField(114.9, 161.8, 72.3),
            'doctor_phone' => new TextField(39.3, 168.2, 147.9),

            // ---------- Page 1 : « Informations confidentielles » -------
            'height' => new TextField(31.4, 182.0, 56.5),
            'weight' => new TextField(102.6, 182.0, 84.6),

            // « Précisez : ……… » under the participation question. The
            // first line starts late because the label is on it.
            'participation_details_1' => new TextField(36.5, 194.8, 150.7),
            'participation_details_2' => new TextField(21.3, 201.3, 165.9),

            // « Si une case est cochée, précisez la fréquence, la gravité
            // et les mesures à prévoir : ……… » — the label eats most of the
            // first line, which is why it is 47 mm and the next two are 166.
            'conditions_details_1' => new TextField(140.0, 244.8, 47.2),
            'conditions_details_2' => new TextField(21.3, 251.2, 165.9),
            'conditions_details_3' => new TextField(21.3, 257.7, 165.9),

            // « Indiquez les maladies importantes ou opérations subies … »
            // — **and its third line is at the top of page 2.** The form
            // itself runs this answer across the page break; nothing here
            // invents that.
            'illnesses_and_operations_1' => new TextField(160.9, 264.1, 26.3),
            'illnesses_and_operations_2' => new TextField(21.3, 270.5, 165.9),
            'illnesses_and_operations_3' => new TextField(21.3, 20.2, 165.9, 10.0, 2),

            // ---------- Page 2 ----------
            // « Mentionnez toute information utile … » — 11 mm of first
            // line, which is one short word. Left as the form prints it:
            // a wrap that skipped it would leave a visible gap on paper.
            'useful_information_1' => new TextField(176.0, 26.6, 11.2, 10.0, 2),
            'useful_information_2' => new TextField(21.3, 33.0, 165.9, 10.0, 2),

            'tetanus_last_booster' => new TextField(56.6, 52.7, 130.6, 10.0, 2),

            'allergies_1' => new TextField(45.8, 72.5, 141.4, 10.0, 2),
            'allergies_2' => new TextField(21.3, 78.9, 165.9, 10.0, 2),
            'allergy_consequences_1' => new TextField(69.5, 85.3, 117.7, 10.0, 2),
            'allergy_consequences_2' => new TextField(21.3, 91.7, 165.9, 10.0, 2),

            // « La personne suit-elle un régime alimentaire particulier ?
            // Si oui, lequel ? » — the question is on its own line and the
            // answer gets exactly one.
            'diet_1' => new TextField(21.3, 111.4, 165.9, 10.0, 2),

            'treatment_1' => new TextField(21.3, 137.6, 167.4, 10.0, 2),
            'treatment_2' => new TextField(21.3, 144.0, 167.4, 10.0, 2),
        ];
    }

    /**
     * The answers that run over several printed lines, and which lines.
     *
     * Keyed by the name the answer carries on the screen and in storage, so
     * an overflow can be reported to the parent under the label they typed
     * it into rather than as « allergies_2 ».
     *
     * `diet` is here with a single line on purpose: it is a free-text
     * answer like the others, it wraps like the others, and the day the
     * federation gives it a second line this map is the only thing that
     * changes.
     *
     * @return array<string, list<string>>
     */
    public static function paragraphLines(): array
    {
        return [
            'participation_details' => ['participation_details_1', 'participation_details_2'],
            'conditions_details' => [
                'conditions_details_1',
                'conditions_details_2',
                'conditions_details_3',
            ],
            'illnesses_and_operations' => [
                'illnesses_and_operations_1',
                'illnesses_and_operations_2',
                'illnesses_and_operations_3',
            ],
            'useful_information' => ['useful_information_1', 'useful_information_2'],
            'allergies' => ['allergies_1', 'allergies_2'],
            'allergy_consequences' => ['allergy_consequences_1', 'allergy_consequences_2'],
            'diet' => ['diet_1'],
            'treatment' => ['treatment_1', 'treatment_2'],
        ];
    }

    /**
     * Every square the form prints that this module knows how to cross.
     *
     * Two sizes, both measured: 2.2 mm for the participation and swimming
     * answers, 1.35 mm for the twelve conditions and for every OUI/NON on
     * page 2.
     *
     * @return array<string, TickBox>
     */
    public static function tickBoxes(): array
    {
        return [
            // « La personne peut-elle participer aux activités proposées ? »
            'participation_yes' => new TickBox(160.61, 186.18, 2.20),
            'participation_no' => new TickBox(172.38, 186.18, 2.20),

            // « La personne sait-elle nager ? » — five printed answers, in
            // the order the form prints them.
            'swimming_very_good' => new TickBox(65.02, 205.40, 2.20),
            'swimming_good' => new TickBox(84.58, 205.40, 2.20),
            'swimming_fair' => new TickBox(96.44, 205.40, 2.20),
            'swimming_poor' => new TickBox(130.47, 205.40, 2.20),
            'swimming_not_at_all' => new TickBox(154.94, 205.40, 2.20),

            // « La personne présente-t-elle, de manière permanente ou
            // régulière : » — three rows of four, left to right, in the
            // order of `Value\HealthSheet::CONDITIONS`. A key out of place
            // here crosses « asthme » for a child who has « diabète », which
            // is why both lists are pinned by a test.
            'condition_diabetes' => new TickBox(22.35, 217.85, 1.35),
            'condition_car_sickness' => new TickBox(62.23, 217.85, 1.35),
            'condition_heart_condition' => new TickBox(104.65, 217.85, 1.35),
            'condition_mental_disability' => new TickBox(144.70, 217.85, 1.35),
            'condition_asthma' => new TickBox(22.35, 225.89, 1.35),
            'condition_rheumatism' => new TickBox(62.23, 225.89, 1.35),
            'condition_skin_condition' => new TickBox(104.65, 225.89, 1.35),
            'condition_motor_disability' => new TickBox(144.70, 225.89, 1.35),
            'condition_epilepsy' => new TickBox(22.35, 233.93, 1.35),
            'condition_bedwetting' => new TickBox(62.23, 233.93, 1.35),
            'condition_sleepwalking' => new TickBox(104.65, 233.93, 1.35),
            'condition_headaches' => new TickBox(144.70, 233.93, 1.35),

            // Page 2. Note that « tétanos » prints NON before OUI and
            // « allergique » prints OUI before NON: the form is not
            // consistent about it, and reading the order off the template
            // is the only thing that keeps a cross on the right square.
            'tetanus_no' => new TickBox(71.12, 44.87, 1.35, 2),
            'tetanus_yes' => new TickBox(86.11, 44.87, 1.35, 2),
            'allergic_yes' => new TickBox(115.74, 64.60, 1.35, 2),
            'allergic_no' => new TickBox(129.88, 64.60, 1.35, 2),
            'treatment_no' => new TickBox(82.72, 123.27, 1.35, 2),
            'treatment_yes' => new TickBox(98.47, 123.27, 1.35, 2),
            'autonomy_yes' => new TickBox(107.78, 149.01, 1.35, 2),
            'autonomy_no' => new TickBox(122.00, 149.01, 1.35, 2),
        ];
    }

    /**
     * The identity lines, which the membership record fills and a parent
     * cannot: they are not answers on the screen and there is nothing a
     * family could shorten if one of them overflowed.
     *
     * Written out rather than derived, and used by the controller to decide
     * what an overflow means — the same distinction
     * `ParentalAuthorizationFilling::PARENT_EDITABLE` draws, from the other
     * end, because here the site supplies ten lines out of forty and there
     * the parent supplies four out of eleven.
     *
     * @return list<string>
     */
    public static function siteSuppliedNames(): array
    {
        return [
            'member_last_name',
            'member_first_name',
            'member_birth_date',
            'member_street',
            'member_street_number',
            'member_box',
            'member_postal_code',
            'member_city',
            'member_phone',
            'member_email',
        ];
    }
}
