<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

use Core\Config\AppClock;

/**
 * The photos of an inventory, as its PDF prints them after the text (#989):
 * which photos, in which order, under which number, and the HTML dompdf
 * lays out two to a row, three rows to a page.
 *
 * Pure: the meters and the incidents are handed in with the JPEG copies
 * already made (`Service\InventoryPhotoRenderer`), and HTML comes out —
 * every caption escaped, every image a `data:image/jpeg;base64,` URI
 * checked before it is printed, since dompdf renders whatever it is given.
 *
 * **The order.** The meters first, in the order of the asset's list, and
 * at the departure each meter's arrival photo beside its departure photo
 * — on the same row, an empty cell put before the pair when it would
 * otherwise straddle two — so the two dials are read side by side; then
 * the incidents' photos, incidents in the order they were reported and
 * each one's photos in the order they were added. The numbers follow that
 * order, and are the ones the incident lines refer to.
 *
 * **The layout is for dompdf, not for a browser.** One fixed-layout table
 * per page and a page break between them, rather than one long table and
 * `page-break-inside` on its rows, which dompdf honours badly; each image's
 * width and height worked out here and written as attributes, because
 * dompdf respects `max-height` badly too; and the frame's `.body td`
 * border and justified text undone inline on every cell.
 *
 * **The sheet signed on paper** (#989, lot F) comes last, on a page of its
 * own and as large as the page allows, since it is read rather than
 * glanced at: `paperSheet()` numbers it after every other photo, and
 * `sheetHtml()` prints it.
 */
final class InventoryPhotoAnnex
{
    public const PER_ROW = 2;

    public const ROWS_PER_PAGE = 3;

    /**
     * The box an image is fitted into, in CSS pixels (dompdf: 96 per inch,
     * about 79 × 50 mm). Measured, not derived: three rows whose captions
     * take four lines fit under the heading at 190; at 200 dompdf already
     * pushes the third row onto a page of its own.
     */
    public const BOX_WIDTH = 300;

    public const BOX_HEIGHT = 190;

    /** How much of a description or a label a caption repeats; the text above has it whole. */
    public const SUBJECT_MAX_CHARACTERS = 60;

    /** What the caption of the sheet signed on paper says. */
    public const SHEET_SUBJECT = "Feuille d'état des lieux signée sur papier";

    /**
     * The box the sheet is fitted into: the A4 frame's width, and its
     * height less the heading and the caption (dompdf, 96 px per inch).
     */
    public const SHEET_BOX_WIDTH = 640;

    public const SHEET_BOX_HEIGHT = 820;

    private const DATA_URI = '#^data:image/jpeg;base64,[A-Za-z0-9+/]++={0,2}$#';

    /**
     * The files whose copies the annex needs, in its order — so they can
     * be made before anything is written.
     *
     * @param MeterConsumption[] $meters
     * @param Incident[] $incidents
     * @return list<int>
     */
    public static function fileIds(ReadingPhase $phase, array $meters, array $incidents): array
    {
        return array_map(
            static fn(array $source): int => $source['fileId'],
            self::sources($phase, $meters, $incidents)
        );
    }

    /**
     * The photos, numbered in the annex's order. One whose copy could not
     * be made (its file gone) is left out and the numbers run on without
     * it: a number with no picture would send the reader looking for one.
     *
     * @param MeterConsumption[] $meters
     * @param Incident[] $incidents only on the departure's
     * @param array<int, array{jpeg: string, width: int, height: int}> $images by file id
     * @return list<InventoryPhoto>
     */
    public static function photos(ReadingPhase $phase, array $meters, array $incidents, array $images): array
    {
        $photos = [];
        foreach (self::sources($phase, $meters, $incidents) as $source) {
            $image = $images[$source['fileId']] ?? null;
            if ($image === null) {
                continue;
            }
            $photos[] = new InventoryPhoto(
                count($photos) + 1,
                $source['fileId'],
                $source['subject'],
                $source['incidentId'],
                $source['meterId'],
                $source['phase'],
                $source['meterValue'],
                $source['takenAt'],
                $image['jpeg'],
                $image['width'],
                $image['height']
            );
        }

        return $photos;
    }

    /**
     * The photo of the sheet signed on paper, numbered after the others
     * (`$photos` as `photos()` returned them).
     *
     * @param list<InventoryPhoto> $photos
     * @param array{jpeg: string, width: int, height: int} $image
     */
    public static function paperSheet(
        array $photos,
        int $fileId,
        array $image,
        \DateTimeImmutable $takenAt
    ): InventoryPhoto {
        return new InventoryPhoto(
            count($photos) + 1,
            $fileId,
            self::SHEET_SUBJECT,
            null,
            null,
            null,
            null,
            $takenAt,
            $image['jpeg'],
            $image['width'],
            $image['height']
        );
    }

    /**
     * The sheet signed on paper, on a page of its own, or nothing.
     */
    public static function sheetHtml(?InventoryPhoto $sheet): string
    {
        if ($sheet === null) {
            return '';
        }

        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $scale = min(self::SHEET_BOX_WIDTH / max(1, $sheet->width), self::SHEET_BOX_HEIGHT / max(1, $sheet->height));
        $width = max(1, (int) floor($sheet->width * $scale));
        $height = max(1, (int) floor($sheet->height * $scale));

        return '<h2 style="page-break-before: always;">Annexe : feuille signée</h2>'
            . '<div style="text-align: center;">' . self::jpegImage($sheet, $width, $height) . '</div>'
            . '<div style="font-size: 8pt; text-align: center; margin-top: 3px;"><strong>Photo ' . $sheet->number
            . '</strong> — ' . $e(self::caption($sheet)) . ', ' . $e(self::moment($sheet->takenAt)) . '</div>';
    }

    /**
     * The numbers of one incident's photos.
     *
     * @param list<InventoryPhoto> $photos
     * @return list<int>
     */
    public static function numbersOf(array $photos, int $incidentId): array
    {
        $numbers = [];
        foreach ($photos as $photo) {
            if ($photo->incidentId === $incidentId) {
                $numbers[] = $photo->number;
            }
        }

        return $numbers;
    }

    /**
     * « photo 3 », « photos 3 et 4 », « photos 3, 4 et 5 » — or null for
     * none.
     *
     * @param list<int> $numbers
     */
    public static function reference(array $numbers): ?string
    {
        if ($numbers === []) {
            return null;
        }
        if (count($numbers) === 1) {
            return 'photo ' . $numbers[0];
        }
        $last = array_pop($numbers);

        return 'photos ' . implode(', ', $numbers) . ' et ' . $last;
    }

    /**
     * « 12/07 à 18 h 40 », on the application clock (Brussels), whatever
     * zone the instant was built in: a column's `CURRENT_TIMESTAMP` and an
     * instant computed in PHP must read the same hour to the renter.
     */
    public static function moment(\DateTimeImmutable $at): string
    {
        $local = $at->setTimezone(AppClock::zone());

        return $local->format('d/m') . ' à ' . $local->format('G') . ' h ' . $local->format('i');
    }

    /**
     * The width and height an image is printed at: as large as fits the
     * box, its proportions kept.
     *
     * @return array{int, int}
     */
    public static function fit(int $width, int $height): array
    {
        $scale = min(self::BOX_WIDTH / max(1, $width), self::BOX_HEIGHT / max(1, $height));

        return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
    }

    /**
     * The annex, or nothing when there is no photo: a heading on a page of
     * its own, then one table per page.
     *
     * @param list<InventoryPhoto> $photos
     */
    public static function html(array $photos): string
    {
        if ($photos === []) {
            return '';
        }

        $pages = array_chunk(self::cells($photos), self::PER_ROW * self::ROWS_PER_PAGE);
        $html = '<h2 style="page-break-before: always;">Annexe : photos</h2>';
        foreach ($pages as $index => $cells) {
            $isLast = $index === count($pages) - 1;
            $html .= '<table class="photo-annex" style="width: 100%; table-layout: fixed; border-collapse: collapse;'
                . ($isLast ? '' : ' page-break-after: always;') . '">';
            foreach (array_chunk($cells, self::PER_ROW) as $row) {
                $html .= '<tr>';
                for ($column = 0; $column < self::PER_ROW; $column++) {
                    $html .= self::cell($row[$column] ?? null);
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        return $html;
    }

    /**
     * The photos in their cells, with an empty one before a meter's pair
     * that would otherwise start in the right-hand column.
     *
     * @param list<InventoryPhoto> $photos
     * @return list<?InventoryPhoto>
     */
    private static function cells(array $photos): array
    {
        $cells = [];
        foreach ($photos as $index => $photo) {
            $next = $photos[$index + 1] ?? null;
            $startsAPair = $photo->phase === ReadingPhase::ARRIVAL
                && $next !== null
                && $next->meterId === $photo->meterId
                && $next->phase === ReadingPhase::DEPARTURE;
            if ($startsAPair && count($cells) % self::PER_ROW !== 0) {
                $cells[] = null;
            }
            $cells[] = $photo;
        }

        return $cells;
    }

    private static function cell(?InventoryPhoto $photo): string
    {
        // Inline, so it wins over the frame's `.body td` (border, padding)
        // and `.body` (justified text) whatever dompdf does with a <style>
        // inside the body.
        $open = '<td style="width: 50%; border: none; padding: 6px 4px 10px 4px; text-align: center;'
            . ' vertical-align: top;">';
        if ($photo === null) {
            return $open . '</td>';
        }

        $e = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        [$width, $height] = self::fit($photo->width, $photo->height);
        $image = self::jpegImage($photo, $width, $height);
        if ($image !== '') {
            $image = '<div style="height: ' . self::BOX_HEIGHT . 'px;">' . $image . '</div>';
        }

        return $open . $image
            . '<div style="font-size: 8pt; line-height: 1.3; text-align: center; margin-top: 3px;">'
            . '<strong>Photo ' . $photo->number . '</strong> — ' . $e(self::caption($photo)) . '<br>'
            . $e(self::moment($photo->takenAt)) . '</div></td>';
    }

    /**
     * The `<img>` of a photo at that size, or nothing: only JPEG bytes, as
     * a `data:` URI of the expected shape, are ever handed to dompdf.
     */
    private static function jpegImage(InventoryPhoto $photo, int $width, int $height): string
    {
        $dataUri = 'data:image/jpeg;base64,' . base64_encode($photo->jpeg);
        if (!str_starts_with($photo->jpeg, "\xFF\xD8\xFF") || preg_match(self::DATA_URI, $dataUri) !== 1) {
            return '';
        }

        return '<img src="' . $dataUri . '" width="' . $width . '" height="' . $height . '" alt="Photo '
            . $photo->number . '">';
    }

    /**
     * « Compteur Électricité, entrée : 1 234,567 kWh », « Incident : Vitre
     * cassée », or the sheet signed on paper.
     */
    private static function caption(InventoryPhoto $photo): string
    {
        if (!$photo->isOfAMeter() && $photo->incidentId === null) {
            return $photo->subject;
        }
        // Shortened, so that no caption runs past three lines and pushes
        // the third row of a page onto the next.
        $subject = mb_strimwidth($photo->subject, 0, self::SUBJECT_MAX_CHARACTERS, '…', 'UTF-8');
        if ($photo->isOfAMeter()) {
            return 'Compteur ' . $subject
                . ($photo->phase === ReadingPhase::DEPARTURE ? ', sortie' : ', entrée')
                . ($photo->meterValue !== null ? ' : ' . $photo->meterValue : '');
        }

        return 'Incident : ' . $subject;
    }

    /**
     * @param MeterConsumption[] $meters
     * @param Incident[] $incidents
     * @return list<array{fileId: int, subject: string, incidentId: ?int, meterId: ?int,
     *     phase: ?ReadingPhase, meterValue: ?string, takenAt: \DateTimeImmutable}>
     */
    private static function sources(ReadingPhase $phase, array $meters, array $incidents): array
    {
        $sources = [];
        foreach ($meters as $meter) {
            $readings = $phase === ReadingPhase::ARRIVAL
                ? [$meter->arrival]
                : [$meter->arrival, $meter->departure];
            foreach ($readings as $reading) {
                if ($reading === null || $reading->fileId === null) {
                    continue;
                }
                $unit = $meter->meter->displayUnit();
                $sources[] = [
                    'fileId' => $reading->fileId,
                    'subject' => $meter->meter->label,
                    'incidentId' => null,
                    'meterId' => $meter->meter->id,
                    'phase' => $reading->phase,
                    'meterValue' => $reading->formatted() . ($unit !== '' ? ' ' . $unit : ''),
                    'takenAt' => $reading->readAt,
                ];
            }
        }

        if ($phase === ReadingPhase::DEPARTURE) {
            foreach ($incidents as $incident) {
                foreach ($incident->photos as $photo) {
                    $sources[] = [
                        'fileId' => $photo->fileId,
                        'subject' => $incident->description,
                        'incidentId' => $incident->id,
                        'meterId' => null,
                        'phase' => null,
                        'meterValue' => null,
                        'takenAt' => $photo->createdAt,
                    ];
                }
            }
        }

        return $sources;
    }
}
