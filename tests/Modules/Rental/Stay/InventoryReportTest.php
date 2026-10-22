<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\IncidentPhoto;
use Modules\Rental\Stay\InventoryPhotoAnnex;
use Modules\Rental\Stay\InventoryKind;
use Modules\Rental\Stay\InventoryReport;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\RentalMeter;
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

    /**
     * An arrival ticked by hand leaves no arrival value: the departure's
     * PDF says so line by line rather than « Aucun », which would read as
     * « nothing changed » when nothing could be compared.
     */
    public function testADepartureWithoutAnArrivalToCompareSaysSo(): void
    {
        $differences = InventoryReport::departureDifferences([
            self::line('Chaises', InventoryKind::QUANTITY, 40, null, '30'),
            self::line('Cuisine propre', InventoryKind::YES_NO, null, null, 'no'),
            self::line('Extincteur', InventoryKind::QUANTITY, 1, null, null),
        ]);

        $this->assertSame([
            "Chaises : 30 à la sortie, sans valeur d'entrée pour comparer",
            "Cuisine propre : non à la sortie, sans valeur d'entrée pour comparer",
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
                1, 1, 'Vitre <cassée>', 5000, IncidentDecision::PENDING, null, null, null, null,
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

    /**
     * The departure's PDF lists what was observed, never what it may cost:
     * an incident still pending is no demand, and the card on the page
     * says so (« sans montant »).
     */
    public function testTheDeparturesPdfListsIncidentsWithoutTheirAmount(): void
    {
        $html = InventoryReport::html(
            ReadingPhase::DEPARTURE,
            [],
            [],
            [new Incident(
                1, 1, 'Vitre cassée', 12345, IncidentDecision::PENDING, null, null, null, null,
                new \DateTimeImmutable('2027-07-04 10:00:00')
            )],
            'Anne Dupont',
            new \DateTimeImmutable('2027-07-04 11:30:00')
        );

        $this->assertStringContainsString('<li>04/07 à 10 h 00 — Vitre cassée</li>', $html);
        $this->assertStringNotContainsString('123,45', $html);
        $this->assertStringNotContainsString('12345', $html);
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

    /**
     * Every time on the PDF is Brussels time (#989): an incident's
     * `created_at` and the validation's instant arrive in UTC here, and the
     * renter reads the hour they lived — two hours later in July.
     */
    public function testTimesAreReadInBrusselsTimeWhateverZoneTheyCameIn(): void
    {
        $utc = new \DateTimeZone('UTC');

        $html = InventoryReport::html(
            ReadingPhase::DEPARTURE,
            [],
            [],
            [new Incident(
                1, 1, 'Porte du réfectoire forcée', null, IncidentDecision::PENDING, null, null, null, null,
                new \DateTimeImmutable('2027-07-12 16:40:00', $utc)
            )],
            'Anne Dupont',
            new \DateTimeImmutable('2027-07-13 08:05:00', $utc)
        );

        $this->assertStringContainsString('<li>12/07 à 18 h 40 — Porte du réfectoire forcée</li>', $html);
        $this->assertStringContainsString('Validé par Anne Dupont le 13/07/2027 à 10:05.', $html);
    }

    /**
     * Each incident names its photos by their number in the annex, which
     * counts the meters' first; an incident without one names none.
     */
    public function testEachIncidentNamesItsPhotosInTheAnnex(): void
    {
        $at = new \DateTimeImmutable('2027-07-12 18:40:00');
        $photo = static fn(int $id, int $incidentId): IncidentPhoto => new IncidentPhoto($id, $incidentId, 1, $id, 1, $at);
        $incidents = [
            new Incident(
                7, 1, 'Porte du réfectoire forcée', null, IncidentDecision::PENDING, null, null, null, null, $at,
                photos: [$photo(71, 7), $photo(72, 7)]
            ),
            new Incident(
                8, 1, 'Vitre <cassée>', null, IncidentDecision::PENDING, null, null, null, null, $at,
                photos: [$photo(81, 8)]
            ),
            new Incident(9, 1, 'Rayure', null, IncidentDecision::PENDING, null, null, null, null, $at),
        ];
        $image = imagecreatetruecolor(8, 6);
        ob_start();
        imagejpeg($image);
        $jpeg = ['jpeg' => (string) ob_get_clean(), 'width' => 8, 'height' => 6];
        // A meter's two photos come first: the incidents' start at 3.
        $meter = new RentalMeter(1, 1, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);
        $reading = static fn(ReadingPhase $phase, int $fileId): MeterReading
            => new MeterReading($fileId, 1, 1, $phase, 1_000_000, $at, $fileId, null, 1);
        $meters = [MeterConsumption::of(
            $meter, $reading(ReadingPhase::ARRIVAL, 11), $reading(ReadingPhase::DEPARTURE, 12), null
        )];
        $photos = InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            $meters,
            $incidents,
            array_fill_keys([11, 12, 71, 72, 81], $jpeg)
        );

        $html = InventoryReport::html(
            ReadingPhase::DEPARTURE, [], $meters, $incidents, 'Anne Dupont', $at, $photos
        );

        $this->assertStringContainsString('<li>12/07 à 18 h 40 — Porte du réfectoire forcée (photos 3 et 4)</li>', $html);
        $this->assertStringContainsString('<li>12/07 à 18 h 40 — Vitre &lt;cassée&gt; (photo 5)</li>', $html);
        $this->assertStringContainsString('<li>12/07 à 18 h 40 — Rayure</li>', $html);
        // The annex follows the line that closes the text.
        $this->assertGreaterThan(strpos($html, 'Validé par'), strpos($html, 'Annexe : photos'));
        $this->assertStringContainsString('<strong>Photo 5</strong> — Incident : Vitre &lt;cassée&gt;', $html);
    }

    /** Without a photo, the PDF has no annex. */
    public function testNoPhotoNoAnnex(): void
    {
        $html = InventoryReport::html(
            ReadingPhase::ARRIVAL,
            [self::line('Chaises', InventoryKind::QUANTITY, 40, '40')],
            [],
            [],
            'Anne Dupont',
            new \DateTimeImmutable('2027-07-01 15:00:00')
        );

        $this->assertStringNotContainsString('Annexe', $html);
    }
}
