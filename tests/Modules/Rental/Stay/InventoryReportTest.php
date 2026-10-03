<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\InventoryKind;
use Modules\Rental\Stay\InventoryReport;
use Modules\Rental\Stay\ReadingPhase;
use PHPUnit\Framework\TestCase;

/**
 * What an inventory says, read off its values (#708, IT-17): the value is
 * the check, so every « problème » and « manquant » is computed from it —
 * and the PDF the renter is sent says « non vérifié » where nobody looked.
 */
final class InventoryReportTest extends TestCase
{
    /**
     * @return array{id: int, label: string, kind: InventoryKind, expected_count: ?int, arrival_value: ?string,
     *     departure_value: ?string, arrival_note: ?string, departure_note: ?string}
     */
    private static function line(
        string $label,
        InventoryKind $kind,
        ?int $expected,
        ?string $arrival = null,
        ?string $departure = null,
        ?string $departureNote = null
    ): array {
        return [
            'id' => 1,
            'label' => $label,
            'kind' => $kind,
            'expected_count' => $expected,
            'arrival_value' => $arrival,
            'departure_value' => $departure,
            'arrival_note' => null,
            'departure_note' => $departureNote,
        ];
    }

    public function testTheArrivalIsReadAgainstTheTemplateAndTheDepartureAgainstTheArrival(): void
    {
        $chairs = self::line('Chaises', InventoryKind::QUANTITY, 40, '38');
        $kitchen = self::line('Cuisine propre', InventoryKind::YES_NO, null);

        $this->assertSame('40', InventoryReport::reference($chairs, ReadingPhase::ARRIVAL));
        $this->assertSame('38', InventoryReport::reference($chairs, ReadingPhase::DEPARTURE));
        $this->assertSame('yes', InventoryReport::reference($kitchen, ReadingPhase::ARRIVAL));
        // An arrival nobody filled in leaves the departure nothing to take.
        $this->assertNull(InventoryReport::reference($kitchen, ReadingPhase::DEPARTURE));
    }

    public function testAnEmptyValueIsUncheckedAndAZeroIsNot(): void
    {
        $lines = [
            self::line('Chaises', InventoryKind::QUANTITY, 40, '0'),
            self::line('Tables', InventoryKind::QUANTITY, 4),
            self::line('Cuisine propre', InventoryKind::YES_NO, null, 'no'),
        ];

        $this->assertSame(1, InventoryReport::uncheckedCount($lines, ReadingPhase::ARRIVAL));
        $this->assertSame(3, InventoryReport::uncheckedCount($lines, ReadingPhase::DEPARTURE));
    }

    public function testTheArrivalsGapsAreComputedNotChosen(): void
    {
        $gaps = InventoryReport::arrivalGaps([
            self::line('Chaises', InventoryKind::QUANTITY, 40, '38'),
            self::line('Tables', InventoryKind::QUANTITY, 4, '4'),
            self::line('Cuisine propre', InventoryKind::YES_NO, null, 'no'),
            self::line('Clés', InventoryKind::QUANTITY, 2),
        ]);

        $this->assertSame(['Chaises : 38 au lieu de 40 attendus', 'Cuisine propre : non'], $gaps);
    }

    public function testTheDeparturesDifferencesAreAgainstTheArrivalNotTheTemplate(): void
    {
        // 38 found at the arrival, 37 left: one chair is missing — not three.
        $differences = InventoryReport::departureDifferences([
            self::line('Chaises', InventoryKind::QUANTITY, 40, '38', '37'),
            self::line('Bancs', InventoryKind::QUANTITY, 6, '6', '7'),
            self::line('Tables', InventoryKind::QUANTITY, 4, '4', '4'),
            self::line('Cuisine propre', InventoryKind::YES_NO, null, 'yes', 'no'),
            self::line('Clés', InventoryKind::QUANTITY, 2, '2'),
        ]);

        $this->assertSame([
            "Chaises : il en manque 1 par rapport à l'entrée",
            "Bancs : il y en a 1 de plus qu'à l'entrée",
            "Cuisine propre : oui à l'entrée, non à la sortie",
        ], $differences);
    }

    public function testThePdfSaysNonVerifieEscapesWhatWasTypedAndNamesWhoValidated(): void
    {
        $html = InventoryReport::html(
            ReadingPhase::DEPARTURE,
            [
                self::line('Chaises <b>', InventoryKind::QUANTITY, 40, '38', null),
                self::line('Cuisine propre', InventoryKind::YES_NO, null, 'yes', 'no', 'Évier <sale>'),
            ],
            [],
            [new Incident(
                1, 1, 'Vitre <cassée>', 5000, IncidentDecision::PENDING, null, null, null, null, null,
                new \DateTimeImmutable('2027-07-04 10:00:00')
            )],
            'Anne Dupont',
            new \DateTimeImmutable('2027-07-04 11:30:00')
        );

        $this->assertStringContainsString('<em>non vérifié</em>', $html);
        $this->assertStringContainsString('Chaises &lt;b&gt;', $html);
        $this->assertStringNotContainsString('<sale>', $html);
        $this->assertStringContainsString('Cuisine propre : Évier &lt;sale&gt;', $html);
        $this->assertStringContainsString('Vitre &lt;cassée&gt;', $html);
        $this->assertStringContainsString("<h2>Différences avec l'entrée</h2>", $html);
        $this->assertStringContainsString('Validé par Anne Dupont le 04/07/2027 à 11:30.', $html);
    }

    public function testTheArrivalsPdfHasNoIncidentsSection(): void
    {
        $html = InventoryReport::html(
            ReadingPhase::ARRIVAL,
            [self::line('Chaises', InventoryKind::QUANTITY, 40, '40')],
            [],
            [],
            'Anne Dupont',
            new \DateTimeImmutable('2027-07-01 15:00:00')
        );

        $this->assertStringContainsString('Écarts avec le modèle</h2><p>Aucun.</p>', $html);
        $this->assertStringNotContainsString('Incidents', $html);
    }
}
