<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * Every position on `templates/autorisation-parentale.pdf`, and nothing
 * else.
 *
 * One map file per document, on purpose: coordinates scattered through a
 * service are coordinates nobody can re-align when the federation moves a
 * margin. `php scripts/pdf-template-grid.php` prints this form with a
 * millimetre grid over it — read a value off that grid, type it here, and
 * look at the result.
 *
 * Millimetres from the top-left corner. A `TextField`'s Y is the BASELINE,
 * because these values sit on printed dotted lines; a `StrikeZone`'s Y is
 * where the stroke crosses the word it cancels.
 */
final class ParentalAuthorizationLayout
{
    /** The template's own page, which every field has to fit inside. */
    public const PAGE_WIDTH_MM = 210.0;
    public const PAGE_HEIGHT_MM = 297.0;

    /**
     * The values the site writes.
     *
     * @return array<string, TextField>
     */
    public static function textFields(): array
    {
        return [
            // « Coordonnées de l'animateur responsable du groupe » — the two
            // names go on the dotted line UNDER their labels (« Prénom » at
            // 25.3, « Nom » at 87.8), each under its own, which is where a
            // hand would write them. The line itself runs 25.9 → 189.6.
            'leader_first_name' => new TextField(27.0, 68.3, 57.0),
            'leader_last_name' => new TextField(88.5, 68.3, 100.0),
            // « Adresse complète » — two dotted lines, so a long address has
            // somewhere to go rather than shrinking to nothing.
            'leader_address' => new TextField(27.0, 82.1, 161.0),
            'leader_address_overflow' => new TextField(27.0, 88.9, 161.0),

            // « Je soussigné(e) (prénom, nom) » — typed by the parent, on the
            // dotted line under the label.
            'signatory_name' => new TextField(27.0, 107.6, 161.0),

            // « autorise (prénom, nom) …………… » — on the label's own line,
            // where the form's dots run.
            'member_name' => new TextField(74.0, 121.4, 105.0),

            // « de l'unité …………… (code de l'unité et nom complet) » — the
            // dots stop at 106.3, and so does the room.
            'unit' => new TextField(42.0, 135.1, 63.0, 9.0),

            // « du ……/……/…… au ……/……/…… » — one date over each run of dots
            // (30.3 → 68.6, then 76 → 115) rather than three fragments over
            // three sub-slots: those are a writing aid, and a date reads as a
            // date.
            'start_date' => new TextField(31.5, 142.0, 36.0),
            'end_date' => new TextField(77.0, 142.0, 36.0),

            // « Fait à …………… le …………… » — « Fait à » ends at 32.3 and its
            // dots at 81.2; « le » starts at 82.6 and its dots end at 154.2.
            'place' => new TextField(36.5, 221.8, 43.5),
            'today' => new TextField(89.0, 221.8, 64.0),
        ];
    }

    /**
     * The printed mentions the form asks to cross out.
     *
     * @return array<string, StrikeZone>
     */
    public static function strikeZones(): array
    {
        return [
            // « père - mère - tuteur - répondant (barrer les mentions
            // inutiles) » — three of the four are struck. Extents measured
            // off the template at 600 dpi, baseline 115.2, so the stroke
            // crosses at 114.2.
            'capacity_father' => new StrikeZone(25.2, 114.2, 7.0),
            'capacity_mother' => new StrikeZone(39.5, 114.2, 8.0),
            'capacity_guardian' => new StrikeZone(54.5, 114.2, 9.9),
            'capacity_sponsor' => new StrikeZone(71.5, 114.2, 16.9),

            // « à participer aux activités des Baladins - Louveteaux -
            // Éclaireurs - Pionniers » — the branches the member is not in.
            // A member in NONE of the four (Staff d'U, Iama, a branch this
            // installation invented) has nothing struck at all, which is why
            // the service decides and this map only says where.
            'branch_baladins' => new StrikeZone(72.8, 128.0, 14.0),
            'branch_louveteaux' => new StrikeZone(90.4, 128.0, 18.8),
            'branch_eclaireurs' => new StrikeZone(112.7, 128.0, 15.7),
            'branch_pionniers' => new StrikeZone(131.9, 128.0, 14.9),

            // « En cas de camp à l'étranger, je l'autorise à quitter le
            // territoire belge … » — note (1) of the form says to cross this
            // sentence out for activities in Belgium, which is the ordinary
            // case. It runs over two printed lines, so it takes two strokes.
            //
            // The second stops at 88.6 rather than at the 92.8 where that
            // line's ink ends: what sits between the two is the « (1) »
            // calling the footnote, and a footnote marker is not part of the
            // sentence being cancelled.
            'abroad_line_1' => new StrikeZone(37.9, 209.2, 152.0),
            'abroad_line_2' => new StrikeZone(37.8, 214.0, 50.8),
        ];
    }

    /**
     * Every name this map is required to carry.
     *
     * Written out rather than derived from the arrays above, which is the
     * entire point: a field silently dropped from the map would leave a
     * blank on a form nobody re-reads, and deriving the list from what the
     * map happens to contain would agree with it whatever it contained.
     *
     * @return array<int, string>
     */
    public static function requiredNames(): array
    {
        return [
            'leader_first_name',
            'leader_last_name',
            'leader_address',
            'leader_address_overflow',
            'signatory_name',
            'member_name',
            'unit',
            'start_date',
            'end_date',
            'place',
            'today',
            'capacity_father',
            'capacity_mother',
            'capacity_guardian',
            'capacity_sponsor',
            'branch_baladins',
            'branch_louveteaux',
            'branch_eclaireurs',
            'branch_pionniers',
            'abroad_line_1',
            'abroad_line_2',
        ];
    }
}
