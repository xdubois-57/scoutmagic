<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What an inventory says, read off its values (#708, IT-17) — the
 * reference each line is checked against, what is still unchecked, the
 * gaps an arrival shows against the template, the differences a departure
 * shows against the arrival, and the body of the PDF the renter is sent.
 *
 * Pure: the lines, the meters and the incidents are handed in, and HTML
 * comes out — every value escaped, since a label or a note is typed by a
 * person and the PDF is rendered by dompdf.
 *
 * **The value is the check.** There is no state any more: a shortage
 * reads in the number, a « non » in the answer, a problem in the note — so
 * the comparisons below are the whole of what used to be « Problème » and
 * « Manquant », computed rather than chosen.
 *
 * @phpstan-type Line array{
 *     id: int,
 *     label: string,
 *     kind: InventoryKind,
 *     expected_count: ?int,
 *     arrival_value: ?string,
 *     departure_value: ?string,
 *     arrival_note: ?string,
 *     departure_note: ?string
 * }
 */
final class InventoryReport
{
    /**
     * What a line is checked against: the template at arrival (the count
     * expected, or « oui »), the arrival at departure.
     *
     * @param Line $line
     */
    public static function reference(array $line, ReadingPhase $phase): ?string
    {
        return $phase === ReadingPhase::ARRIVAL
            ? $line['kind']->arrivalReference($line['expected_count'])
            : $line['arrival_value'];
    }

    /**
     * @param Line $line
     */
    public static function value(array $line, ReadingPhase $phase): ?string
    {
        return $phase === ReadingPhase::ARRIVAL ? $line['arrival_value'] : $line['departure_value'];
    }

    /**
     * @param Line $line
     */
    public static function note(array $line, ReadingPhase $phase): ?string
    {
        return $phase === ReadingPhase::ARRIVAL ? $line['arrival_note'] : $line['departure_note'];
    }

    /**
     * How many lines nobody has looked at for this phase.
     *
     * @param list<Line> $lines
     */
    public static function uncheckedCount(array $lines, ReadingPhase $phase): int
    {
        return count(array_filter($lines, static fn(array $line): bool => self::value($line, $phase) === null));
    }

    /**
     * What the arrival found against the template: « Chaises : 38 au lieu
     * de 40 attendus », « Cuisine propre : non ».
     *
     * @param list<Line> $lines
     * @return list<string>
     */
    public static function arrivalGaps(array $lines): array
    {
        $gaps = [];
        foreach ($lines as $line) {
            $found = $line['arrival_value'];
            $expected = $line['kind']->arrivalReference($line['expected_count']);
            if ($found === null || $expected === null || $found === $expected) {
                continue;
            }

            $gaps[] = $line['kind'] === InventoryKind::YES_NO
                ? $line['label'] . ' : non'
                : $line['label'] . ' : ' . $found . ' au lieu de ' . $expected . ' attendus';
        }

        return $gaps;
    }

    /**
     * What changed between the arrival and the departure, computed:
     * « Chaises : il en manque 1 par rapport à l'entrée », « Cuisine
     * propre : oui à l'entrée, non à la sortie ».
     *
     * @param list<Line> $lines
     * @return list<string>
     */
    public static function departureDifferences(array $lines): array
    {
        $differences = [];
        foreach ($lines as $line) {
            $before = $line['arrival_value'];
            $after = $line['departure_value'];
            if ($before === null || $after === null || $before === $after) {
                continue;
            }

            if ($line['kind'] === InventoryKind::YES_NO) {
                $differences[] = $line['label'] . ' : ' . mb_strtolower($line['kind']->display($before))
                    . " à l'entrée, " . mb_strtolower($line['kind']->display($after)) . ' à la sortie';
                continue;
            }

            $delta = (int) $after - (int) $before;
            $differences[] = $line['label'] . ' : ' . ($delta < 0
                ? 'il en manque ' . -$delta . " par rapport à l'entrée"
                : 'il y en a ' . $delta . " de plus qu'à l'entrée");
        }

        return $differences;
    }

    /**
     * The body of the PDF a validated inventory produces.
     *
     * @param list<Line> $lines
     * @param MeterConsumption[] $meters
     * @param Incident[] $incidents only on the departure's
     */
    public static function html(
        ReadingPhase $phase,
        array $lines,
        array $meters,
        array $incidents,
        string $validatedBy,
        \DateTimeImmutable $validatedAt
    ): string {
        $e = static fn(?string $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $isArrival = $phase === ReadingPhase::ARRIVAL;
        $html = '';

        if ($lines !== []) {
            $html .= '<h2>Éléments</h2><table class="items"><thead><tr><th>Élément</th><th>'
                . ($isArrival ? 'Attendu' : "À l'entrée")
                . '</th><th>Constaté</th><th>Commentaire</th></tr></thead><tbody>';
            foreach ($lines as $line) {
                $value = self::value($line, $phase);
                $html .= '<tr><td>' . $e($line['label']) . '</td><td>'
                    . $e($line['kind']->display(self::reference($line, $phase))) . '</td><td>'
                    . ($value === null ? '<em>non vérifié</em>' : $e($line['kind']->display($value))) . '</td><td>'
                    . $e(self::note($line, $phase)) . '</td></tr>';
            }
            $html .= '</tbody></table>';

            $findings = $isArrival ? self::arrivalGaps($lines) : self::departureDifferences($lines);
            $html .= '<h2>' . ($isArrival ? 'Écarts avec le modèle' : "Différences avec l'entrée") . '</h2>';
            $html .= self::bulletList($findings, $e);

            if (!$isArrival) {
                $commented = array_values(array_filter(
                    $lines,
                    static fn(array $line): bool => ($line['departure_note'] ?? '') !== ''
                ));
                if ($commented !== []) {
                    $html .= '<h2>Éléments commentés</h2><ul>';
                    foreach ($commented as $line) {
                        $html .= '<li>' . $e($line['label']) . ' : ' . $e($line['departure_note']) . '</li>';
                    }
                    $html .= '</ul>';
                }
            }
        }

        if ($meters !== []) {
            $html .= '<h2>Compteurs</h2><table class="items"><thead><tr><th>Compteur</th><th>Entrée</th>'
                . ($isArrival ? '' : '<th>Sortie</th><th>Consommation</th>') . '</tr></thead><tbody>';
            foreach ($meters as $meter) {
                $html .= '<tr><td>' . $e($meter->meter->label) . '</td>'
                    . '<td>' . $e($meter->arrival?->formatted() ?? '—') . '</td>'
                    . ($isArrival ? '' : '<td>' . $e($meter->departure?->formatted() ?? '—') . '</td><td>'
                        . $e($meter->formattedConsumption()) . '</td>')
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!$isArrival) {
            $html .= '<h2>Incidents et dégâts</h2>';
            $html .= self::bulletList(
                array_map(static fn(Incident $incident): string => $incident->description, $incidents),
                $e
            );
        }

        return $html . '<p class="validated">Validé par ' . $e($validatedBy) . ' le '
            . $e($validatedAt->format('d/m/Y à H:i')) . '.</p>';
    }

    /**
     * @param list<string> $texts unescaped
     * @param \Closure(string): string $e
     */
    private static function bulletList(array $texts, \Closure $e): string
    {
        if ($texts === []) {
            return '<p>Aucun.</p>';
        }

        $html = '<ul>';
        foreach ($texts as $text) {
            $html .= '<li>' . $e($text) . '</li>';
        }

        return $html . '</ul>';
    }
}
