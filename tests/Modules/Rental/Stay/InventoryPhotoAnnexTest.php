<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Stay;

use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\IncidentPhoto;
use Modules\Rental\Stay\InventoryPhoto;
use Modules\Rental\Stay\InventoryPhotoAnnex;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use PHPUnit\Framework\TestCase;

/**
 * The photos of an inventory's PDF (#989, lot C): the meters first, each
 * meter's arrival beside its departure, then the incidents in the order
 * they were reported; numbered in that order; two to a row and three rows
 * to a page; every caption escaped and every image a checked JPEG data
 * URI.
 */
final class InventoryPhotoAnnexTest extends TestCase
{
    // ── The order and the numbers ───────────────────────────────────────

    public function testTheMetersComeFirstArrivalBesideDepartureThenTheIncidentsInOrder(): void
    {
        $meters = [
            $this->meter(1, 'Électricité', arrivalFile: 11, departureFile: 12),
            $this->meter(2, 'Eau', arrivalFile: 21, departureFile: null),
        ];
        $incidents = [
            $this->incident(7, 'Porte forcée', [71, 72]),
            $this->incident(8, 'Vitre cassée', [81]),
            $this->incident(9, 'Sans photo', []),
        ];

        $this->assertSame([11, 12, 21, 71, 72, 81], InventoryPhotoAnnex::fileIds(ReadingPhase::DEPARTURE, $meters, $incidents));
    }

    /** The arrival's PDF shows the arrival's readings and no incident. */
    public function testTheArrivalHasItsReadingsOnly(): void
    {
        $meters = [$this->meter(1, 'Électricité', arrivalFile: 11, departureFile: 12)];

        $this->assertSame(
            [11],
            InventoryPhotoAnnex::fileIds(ReadingPhase::ARRIVAL, $meters, [$this->incident(7, 'Porte', [71])])
        );
    }

    /** A photo with no copy is left out, and the numbers run on without a gap. */
    public function testAPhotoWithoutItsCopyIsLeftOutAndTheNumbersRunOn(): void
    {
        $photos = InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            [],
            [$this->incident(7, 'Porte forcée', [71, 72, 73])],
            [71 => $this->image(), 73 => $this->image()]
        );

        $this->assertSame([1, 2], array_map(static fn(InventoryPhoto $photo): int => $photo->number, $photos));
        $this->assertSame([71, 73], array_map(static fn(InventoryPhoto $photo): int => $photo->fileId, $photos));
        $this->assertSame([1, 2], InventoryPhotoAnnex::numbersOf($photos, 7));
        $this->assertSame([], InventoryPhotoAnnex::numbersOf($photos, 8));
    }

    public function testAReferenceNamesOneTwoOrMorePhotos(): void
    {
        $this->assertNull(InventoryPhotoAnnex::reference([]));
        $this->assertSame('photo 3', InventoryPhotoAnnex::reference([3]));
        $this->assertSame('photos 3 et 4', InventoryPhotoAnnex::reference([3, 4]));
        $this->assertSame('photos 3, 4 et 5', InventoryPhotoAnnex::reference([3, 4, 5]));
    }

    /** On the application clock, whatever zone the instant came in. */
    public function testAMomentIsReadInBrusselsTime(): void
    {
        $utc = new \DateTimeZone('UTC');

        $this->assertSame('12/07 à 18 h 40', InventoryPhotoAnnex::moment(new \DateTimeImmutable('2027-07-12 16:40', $utc)));
        $this->assertSame('15/01 à 9 h 05', InventoryPhotoAnnex::moment(new \DateTimeImmutable('2027-01-15 08:05', $utc)));
    }

    // ── The layout ──────────────────────────────────────────────────────

    public function testNoPhotoNoAnnex(): void
    {
        $this->assertSame('', InventoryPhotoAnnex::html([]));
    }

    /** Seven photos: a page of three rows of two, then a page of one. */
    public function testTwoToARowThreeRowsToAPageAndABreakBetweenPages(): void
    {
        $html = InventoryPhotoAnnex::html($this->incidentPhotos(7));

        $this->assertStringContainsString('<h2 style="page-break-before: always;">Annexe : photos</h2>', $html);
        $tables = explode('</table>', $html);
        array_pop($tables);
        $this->assertCount(2, $tables);
        $this->assertSame(3, substr_count($tables[0], '<tr>'));
        $this->assertSame(6, substr_count($tables[0], '<td '));
        $this->assertStringContainsString('table-layout: fixed;', $tables[0]);
        $this->assertStringContainsString('page-break-after: always;', $tables[0]);
        $this->assertSame(1, substr_count($tables[1], '<tr>'));
        $this->assertSame(2, substr_count($tables[1], '<td '), 'the last row is completed with an empty cell');
        $this->assertStringNotContainsString('page-break-after', $tables[1]);
        // The frame's `.body td` border and justified text are undone.
        $this->assertSame(8, substr_count($html, '<td style="width: 50%; border: none;'));
        $this->assertSame(8, substr_count($html, 'padding: 6px 4px 10px 4px; text-align: center;'));
    }

    /**
     * A meter's two photos share a row: an empty cell goes before the
     * pair rather than letting it straddle two rows.
     */
    public function testAMetersPairIsNeverSplitAcrossTwoRows(): void
    {
        $photos = InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            [
                $this->meter(1, 'Électricité', arrivalFile: 11, departureFile: null),
                $this->meter(2, 'Eau', arrivalFile: 21, departureFile: 22),
            ],
            [],
            [11 => $this->image(), 21 => $this->image(), 22 => $this->image()]
        );

        $rows = explode('</tr>', InventoryPhotoAnnex::html($photos));
        $this->assertStringContainsString('Photo 1</strong>', $rows[0]);
        $this->assertStringNotContainsString('Photo 2</strong>', $rows[0]);
        $this->assertStringContainsString('Photo 2</strong>', $rows[1]);
        $this->assertStringContainsString('Photo 3</strong>', $rows[1]);
    }

    public function testCaptionsNameTheSubjectTheReadingAndTheMoment(): void
    {
        $photos = InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            [$this->meter(1, 'Électricité', arrivalFile: 11, departureFile: 12)],
            [$this->incident(7, 'Porte du réfectoire forcée', [71])],
            [11 => $this->image(), 12 => $this->image(), 71 => $this->image()]
        );

        $html = InventoryPhotoAnnex::html($photos);

        $this->assertStringContainsString(
            '<strong>Photo 1</strong> — Compteur Électricité, entrée : 1 000,000 kWh<br>01/07 à 17 h 00',
            $html
        );
        $this->assertStringContainsString(
            '<strong>Photo 2</strong> — Compteur Électricité, sortie : 1 342,500 kWh<br>04/07 à 12 h 00',
            $html
        );
        $this->assertStringContainsString(
            '<strong>Photo 3</strong> — Incident : Porte du réfectoire forcée<br>04/07 à 18 h 40',
            $html
        );
    }

    /** What was typed is escaped, and a long description is shortened under the photo. */
    public function testCaptionsAreEscapedAndShortened(): void
    {
        $long = 'Porte <b>forcée</b> & ' . str_repeat('très abîmée ', 10);
        $photos = InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            [$this->meter(1, 'Eau "froide" <i>', arrivalFile: 11, departureFile: null)],
            [$this->incident(7, $long, [71])],
            [11 => $this->image(), 71 => $this->image()]
        );

        $html = InventoryPhotoAnnex::html($photos);

        $this->assertStringContainsString('Compteur Eau &quot;froide&quot; &lt;i&gt;, entrée', $html);
        $this->assertStringContainsString('Incident : Porte &lt;b&gt;forcée&lt;/b&gt; &amp; très', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('…<br>', $html);
    }

    /** Sized by the server to fit the box, its proportions kept, as attributes dompdf respects. */
    public function testTheImageIsAJpegDataUriSizedToFitTheBox(): void
    {
        $jpeg = $this->jpeg();
        $landscape = new InventoryPhoto(1, 11, 'Porte', 7, null, null, null, new \DateTimeImmutable(), $jpeg, 1600, 1200);
        $portrait = new InventoryPhoto(2, 12, 'Porte', 7, null, null, null, new \DateTimeImmutable(), $jpeg, 1200, 1600);

        $html = InventoryPhotoAnnex::html([$landscape, $portrait]);

        $this->assertSame([253, 190], InventoryPhotoAnnex::fit(1600, 1200));
        $this->assertSame([142, 190], InventoryPhotoAnnex::fit(1200, 1600));
        $this->assertSame([300, 100], InventoryPhotoAnnex::fit(3000, 1000));
        $this->assertStringContainsString(
            '<img src="data:image/jpeg;base64,' . base64_encode($jpeg) . '" width="253" height="190" alt="Photo 1">',
            $html
        );
        $this->assertStringContainsString('width="142" height="190" alt="Photo 2">', $html);
    }

    /** Bytes that are not a JPEG never reach dompdf as an image; the caption still prints. */
    public function testBytesThatAreNotAJpegAreNotPrinted(): void
    {
        $png = new InventoryPhoto(1, 11, 'Porte', 7, null, null, null, new \DateTimeImmutable(), "\x89PNG\r\n\x1a\nxx", 10, 10);

        $html = InventoryPhotoAnnex::html([$png]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<strong>Photo 1</strong>', $html);
    }

    // ── The sheet signed on paper (#989, lot F) ─────────────────────────

    /**
     * The sheet comes after every other photo, under the next number, on a
     * page of its own and as large as the page allows, its proportions
     * kept.
     */
    public function testTheSheetSignedOnPaperIsTheLastPhotoOnAPageOfItsOwn(): void
    {
        $photos = $this->incidentPhotos(3);
        $sheet = InventoryPhotoAnnex::paperSheet(
            $photos,
            99,
            ['jpeg' => $this->jpeg(), 'width' => 1230, 'height' => 1640],
            new \DateTimeImmutable('2027-07-04 09:30', new \DateTimeZone('UTC'))
        );

        $this->assertSame(4, $sheet->number);
        $this->assertSame(99, $sheet->fileId);
        $html = InventoryPhotoAnnex::sheetHtml($sheet);
        $this->assertStringStartsWith('<h2 style="page-break-before: always;">Annexe : feuille signée</h2>', $html);
        $this->assertStringContainsString('<img src="data:image/jpeg;base64,', $html);
        // 1 230 × 1 640 fitted into the 640 × 820 box: half, its height the limit.
        $this->assertStringContainsString('width="615" height="820" alt="Photo 4">', $html);
        $this->assertStringContainsString(
            "<strong>Photo 4</strong> — Feuille d&#039;état des lieux signée sur papier, 04/07 à 11 h 30",
            $html
        );
        $this->assertSame('', InventoryPhotoAnnex::sheetHtml(null));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function meter(int $id, string $label, ?int $arrivalFile, ?int $departureFile): MeterConsumption
    {
        $utc = new \DateTimeZone('UTC');
        $meter = new RentalMeter($id, 1, $label, MeterKind::ELECTRICITY, 'kWh', null);
        $reading = static fn(ReadingPhase $phase, int $value, string $at, ?int $fileId): MeterReading => new MeterReading(
            $id * 10 + ($phase === ReadingPhase::ARRIVAL ? 1 : 2),
            1,
            $id,
            $phase,
            $value,
            new \DateTimeImmutable($at, $utc),
            $fileId,
            null,
            1
        );

        return MeterConsumption::of(
            $meter,
            $reading(ReadingPhase::ARRIVAL, 1_000_000, '2027-07-01 15:00', $arrivalFile),
            $reading(ReadingPhase::DEPARTURE, 1_342_500, '2027-07-04 10:00', $departureFile),
            null
        );
    }

    /** @param list<int> $fileIds */
    private function incident(int $id, string $description, array $fileIds): Incident
    {
        $at = new \DateTimeImmutable('2027-07-04 16:40', new \DateTimeZone('UTC'));
        $photos = [];
        foreach ($fileIds as $position => $fileId) {
            $photos[] = new IncidentPhoto($fileId * 10, $id, 1, $fileId, $position + 1, $at);
        }

        return new Incident(
            $id, 1, $description, null, IncidentDecision::PENDING, null, null, null, null, $at, photos: $photos
        );
    }

    /** @return list<InventoryPhoto> */
    private function incidentPhotos(int $count): array
    {
        $fileIds = range(1, $count);

        return InventoryPhotoAnnex::photos(
            ReadingPhase::DEPARTURE,
            [],
            [$this->incident(7, 'Porte forcée', $fileIds)],
            array_fill_keys($fileIds, $this->image())
        );
    }

    /** @return array{jpeg: string, width: int, height: int} */
    private function image(): array
    {
        return ['jpeg' => $this->jpeg(), 'width' => 16, 'height' => 12];
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(16, 12);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }
}
