<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\BlankInventorySheet;
use Modules\Rental\Stay\InventoryKind;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use PHPUnit\Framework\TestCase;

/**
 * « Imprimer la liste à vérifier » (#989, lot F): the blank checklist of a
 * phase — each item with what it is checked against and a box to tick,
 * each meter with an empty field, free lines for the incidents, two dated
 * signature frames — every label escaped.
 */
final class BlankInventorySheetTest extends TestCase
{
    /**
     * @return array{id: int, label: string, kind: InventoryKind, expected_count: ?int, arrival_value: ?string,
     *     departure_value: ?string, arrival_note: ?string, departure_note: ?string}
     */
    private static function line(string $label, InventoryKind $kind, ?int $expected, ?string $arrival = null): array
    {
        return [
            'id' => 1,
            'label' => $label,
            'kind' => $kind,
            'expected_count' => $expected,
            'arrival_value' => $arrival,
            'departure_value' => null,
            'arrival_note' => null,
            'departure_note' => null,
        ];
    }

    private static function meter(?int $arrivalMilli): MeterConsumption
    {
        $meter = new RentalMeter(1, 1, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $arrival = $arrivalMilli !== null
            ? new MeterReading(
                1, 1, 1, ReadingPhase::ARRIVAL, $arrivalMilli, new \DateTimeImmutable('2027-07-01 15:00'), null, null, 1
            )
            : null;

        return MeterConsumption::of($meter, $arrival, null, null);
    }

    /** The cells of the row whose first cell is this label, as text. */
    private static function rowOf(string $html, string $label): string
    {
        preg_match('#<tr><td>' . preg_quote($label, '#') . '.*?</tr>#u', $html, $row);

        $cells = strip_tags(str_replace('</td>', ' | ', $row[0] ?? ''));
        $text = html_entity_decode($cells, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
    }

    public function testTheArrivalSheetChecksAgainstTheTemplate(): void
    {
        $html = BlankInventorySheet::html(
            ReadingPhase::ARRIVAL,
            [
                // Whatever an arrival already holds, its sheet reads the template.
                self::line('Chaises', InventoryKind::QUANTITY, 40, '38'),
                self::line('Cuisine propre', InventoryKind::YES_NO, null),
            ],
            [self::meter(null)]
        );

        $this->assertStringContainsString('La référence est celle du modèle du bien.', $html);
        $this->assertSame('Chaises | 40 | ☐ | | |', self::rowOf($html, 'Chaises'));
        $this->assertSame('Cuisine propre | Oui | ☐ | | |', self::rowOf($html, 'Cuisine propre'));
        // The meter's field is left empty, and there is no arrival column.
        $this->assertSame('Électricité (kWh) | |', self::rowOf($html, 'Électricité (kWh)'));
        $this->assertStringContainsString("<th>Relevé d'entrée</th>", $html);
        $this->assertStringNotContainsString('<th>Entrée</th>', $html);
    }

    public function testTheDepartureSheetChecksAgainstWhatTheArrivalFound(): void
    {
        $html = BlankInventorySheet::html(
            ReadingPhase::DEPARTURE,
            [
                self::line('Chaises', InventoryKind::QUANTITY, 40, '38'),
                self::line('Cuisine propre', InventoryKind::YES_NO, null, 'no'),
            ],
            [self::meter(1_234_567)]
        );

        $this->assertSame('Chaises | 38 | ☐ | | |', self::rowOf($html, 'Chaises'));
        $this->assertSame('Cuisine propre | Non | ☐ | | |', self::rowOf($html, 'Cuisine propre'));
        // The arrival's reading beside the empty field of the departure's.
        $this->assertSame('Électricité (kWh) | 1 234,567 | |', self::rowOf($html, 'Électricité (kWh)'));
        $this->assertStringContainsString('<th>Relevé de sortie</th>', $html);
    }

    /**
     * An arrival with no values — not walked line by line, or a line
     * nobody looked at — leaves the departure the template's, line by
     * line, rather than nothing to check against.
     */
    public function testADepartureAfterAnArrivalWithoutValuesTakesTheTemplates(): void
    {
        $lines = [
            self::line('Chaises', InventoryKind::QUANTITY, 40),
            self::line('Tables', InventoryKind::QUANTITY, 4, '3'),
            self::line('Cuisine propre', InventoryKind::YES_NO, null),
        ];

        $this->assertSame('40', BlankInventorySheet::expected($lines[0], ReadingPhase::DEPARTURE));
        $this->assertSame('3', BlankInventorySheet::expected($lines[1], ReadingPhase::DEPARTURE));
        $this->assertSame('Oui', BlankInventorySheet::expected($lines[2], ReadingPhase::DEPARTURE));
        $html = BlankInventorySheet::html(ReadingPhase::DEPARTURE, $lines, [self::meter(null)]);
        $this->assertSame('Chaises | 40 | ☐ | | |', self::rowOf($html, 'Chaises'));
        $this->assertSame('Électricité (kWh) | — | |', self::rowOf($html, 'Électricité (kWh)'));
    }

    public function testFreeLinesForTheIncidentsAndTwoDatedSignatureFrames(): void
    {
        $html = BlankInventorySheet::html(ReadingPhase::DEPARTURE, [], []);

        $this->assertStringContainsString('<h2>Incidents et remarques</h2>', $html);
        $incidents = substr($html, (int) strpos($html, 'Incidents et remarques'));
        $incidents = substr($incidents, 0, (int) strpos($incidents, '</table>'));
        $this->assertSame(BlankInventorySheet::INCIDENT_LINES + 1, substr_count($incidents, '<tr>'));
        $this->assertSame(1, substr_count($html, '<strong>Le locataire</strong>'));
        $this->assertSame(1, substr_count($html, '<strong>Le gestionnaire</strong>'));
        $this->assertSame(2, substr_count($html, 'Date : ……/……/…………'));
        // Nothing to walk: no item table, no meter table.
        $this->assertStringNotContainsString('<h2>Éléments</h2>', $html);
        $this->assertStringNotContainsString('<h2>Compteurs</h2>', $html);
    }

    public function testEveryLabelIsEscaped(): void
    {
        $html = BlankInventorySheet::html(
            ReadingPhase::ARRIVAL,
            [self::line('<img src=x onerror=alert(1)>', InventoryKind::QUANTITY, 2)],
            []
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }
}
