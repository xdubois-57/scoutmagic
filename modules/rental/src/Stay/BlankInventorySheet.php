<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * The blank checklist « Imprimer la liste à vérifier » prints (#989, lot
 * F): what is walked through on site, pen in hand, and signed by both
 * parties when the inventory is « Signé sur papier ».
 *
 * Pure: the lines and the meters are handed in, and HTML comes out —
 * every label escaped, since a manager typed it and dompdf renders it.
 *
 * Each item comes with what it is checked against and a box to tick: at
 * the arrival the asset's template (`InventoryKind::arrivalReference()`),
 * at the departure the value found at the arrival — or the template's,
 * line by line, when the arrival has none (an arrival without a full
 * inventory, or a line nobody looked at). Each meter gets an empty field,
 * beside its arrival reading at the departure. Then free lines for the
 * incidents, and two signature frames with a date.
 *
 * Nothing on it is a value the renter has not seen: no amount, no
 * decision — the same rule as the signing screen.
 *
 * @phpstan-import-type Line from InventoryReport
 */
final class BlankInventorySheet
{
    /** The free lines left for incidents and remarks. */
    public const INCIDENT_LINES = 6;

    /** A box to tick, in DejaVu Sans (the PDF's font). */
    private const BOX = '☐';

    /**
     * What a line is checked against on the sheet, in words (« 40 »,
     * « Oui »), or null when there is nothing to compare with.
     *
     * @param Line $line
     */
    public static function expected(array $line, ReadingPhase $phase): ?string
    {
        $template = $line['kind']->arrivalReference($line['expected_count']);
        $reference = $phase === ReadingPhase::ARRIVAL ? $template : ($line['arrival_value'] ?? $template);

        return $reference !== null ? $line['kind']->display($reference) : null;
    }

    /**
     * @param list<Line> $lines
     * @param MeterConsumption[] $meters
     */
    public static function html(ReadingPhase $phase, array $lines, array $meters): string
    {
        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isArrival = $phase === ReadingPhase::ARRIVAL;
        // The frame's `.body td` draws the borders; a cell left to be
        // written in needs the height of a handwritten line.
        $blank = '<td style="height: 22px;">&nbsp;</td>';

        // Every paragraph set left, against the frame's justified body.
        $html = '<p style="text-align: left;">À vérifier ensemble sur place. Cochez ce qui est conforme, '
            . 'notez ce qui ne l\'est pas, '
            . 'puis signez tous les deux en bas de la feuille.</p>';

        if ($lines !== []) {
            $html .= '<h2>Éléments</h2><p style="text-align: left;">'
                . ($isArrival
                    ? 'La référence est celle du modèle du bien.'
                    : "La référence est ce qui a été constaté à l'entrée ; à défaut, le modèle du bien.")
                . '</p><table class="items"><thead><tr><th>Élément</th><th>Référence</th>'
                . '<th style="text-align: center;">Conforme</th><th>Constaté</th><th>Commentaire</th></tr></thead>'
                . '<tbody>';
            foreach ($lines as $line) {
                $html .= '<tr><td>' . $e($line['label']) . '</td><td>' . $e(self::expected($line, $phase) ?? '—')
                    . '</td><td style="text-align: center; font-size: 14pt;">' . self::BOX . '</td>'
                    . $blank . $blank . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($meters !== []) {
            $html .= '<h2>Compteurs</h2><table class="items"><thead><tr><th>Compteur</th>'
                . ($isArrival ? '' : '<th>Entrée</th>')
                . '<th>' . ($isArrival ? 'Relevé d\'entrée' : 'Relevé de sortie') . '</th></tr></thead><tbody>';
            foreach ($meters as $meter) {
                $unit = $meter->meter->displayUnit();
                $html .= '<tr><td>' . $e($meter->meter->label . ($unit !== '' ? ' (' . $unit . ')' : '')) . '</td>'
                    . ($isArrival ? '' : '<td>' . $e($meter->arrival?->formatted() ?? '—') . '</td>')
                    . $blank . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<h2>Incidents et remarques</h2><table class="items"><thead><tr><th style="width: 22%;">Heure</th>'
            . '<th>Ce qui a été constaté</th></tr></thead><tbody>'
            . str_repeat('<tr>' . $blank . $blank . '</tr>', self::INCIDENT_LINES)
            . '</tbody></table>';

        $frame = static fn(string $who): string => '<td style="width: 50%; vertical-align: top; padding: 6px 8px;'
            . ' text-align: left;"><strong>' . $who . '</strong><div style="margin-top: 8px;">Nom :</div>'
            . '<div style="margin-top: 8px;">Date : ……/……/…………</div>'
            . '<div style="margin-top: 8px;">Signature :</div><div style="height: 70px;"></div></td>';

        return $html . '<h2>Signatures</h2>'
            . '<table class="signatures" style="width: 100%; table-layout: fixed; page-break-inside: avoid;"><tr>'
            . $frame('Le locataire') . $frame('Le gestionnaire') . '</tr></table>'
            . '<p style="text-align: left;">Signé sur papier, l\'état des lieux reprend la photo de cette '
            . 'feuille dans '
            . 'le PDF envoyé au locataire.</p>';
    }
}
