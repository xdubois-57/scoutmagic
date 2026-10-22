<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\IncidentPhoto;
use Modules\Rental\Stay\MeterConsumption;
use Modules\Rental\Stay\MeterKind;
use Modules\Rental\Stay\MeterReading;
use Modules\Rental\Stay\ReadingPhase;
use Modules\Rental\Stay\RentalMeter;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The « + Photo » tile (#989, lot B) as `_incidents.html.twig` and
 * `_inventory_meters.html.twig` render it through the real Twig
 * environment: the menu and its two pickers, the one named field that
 * carries the photos, the thumbnails from the `thumb` derivative, the
 * crosses behind `data-confirm`, and the limit.
 *
 * The behaviour itself is tests/js/rental-photo-tile.test.js.
 */
final class PhotoTileTemplateTest extends TestCase
{
    private function renderIncidents(bool $open, Incident ...$incidents): \DOMXPath
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);

        return self::xpath($twig->render('@rental/management/_incidents.html.twig', [
            'incidents' => $incidents,
            'incident_decisions' => IncidentDecision::decidable(),
            'incidents_open' => $open,
            'max_incident_photos' => RentalStayService::MAX_INCIDENT_PHOTOS,
            'asset' => ['id' => 3],
            'booking' => ['id' => 9],
        ]));
    }

    private static function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new \DOMXPath($dom);
    }

    private function incident(int $id, int $photos): Incident
    {
        $list = [];
        for ($i = 1; $i <= $photos; $i++) {
            $list[] = new IncidentPhoto(
                $id * 10 + $i,
                $id,
                9,
                $id * 100 + $i,
                $i,
                new \DateTimeImmutable('2027-07-20 10:00:00')
            );
        }

        return new Incident(
            $id,
            9,
            'Vitre cassée',
            5000,
            IncidentDecision::PENDING,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2027-07-20 10:00:00'),
            photos: $list
        );
    }

    private static function attr(\DOMXPath $xpath, string $query, string $attribute): string
    {
        $node = $xpath->query($query)?->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, $query);

        return $node->getAttribute($attribute);
    }

    private static function nodes(\DOMXPath $xpath, string $query): int
    {
        return $xpath->query($query)?->length ?? 0;
    }

    public function testTheReportFormHasTheTileItsTwoChoicesAndTheFieldThatCarriesThePhotos(): void
    {
        $xpath = $this->renderIncidents(true);
        $report = '//form[@action="/mes-locations/incident"]';

        $this->assertSame('multipart/form-data', self::attr($xpath, $report, 'enctype'));
        $this->assertSame('5', self::attr($xpath, $report . '//*[@data-photo-tile]', 'data-photo-max'));
        $this->assertSame('0', self::attr($xpath, $report . '//*[@data-photo-tile]', 'data-photo-attached'));
        $this->assertSame('+ Photo', trim($xpath->query($report . '//button[@data-bs-toggle="dropdown"]')?->item(0)?->textContent ?? ''));
        $this->assertSame(
            ['Prendre une photo', 'Choisir dans la galerie'],
            array_map(
                static fn(\DOMNode $node): string => trim($node->textContent),
                iterator_to_array($xpath->query($report . '//*[@data-photo-pick]') ?: [])
            )
        );

        $camera = $report . '//input[@data-photo-source="camera"]';
        $gallery = $report . '//input[@data-photo-source="gallery"]';
        $this->assertSame('environment', self::attr($xpath, $camera, 'capture'));
        $this->assertSame('', self::attr($xpath, $gallery, 'capture'));
        $this->assertSame(1, self::nodes($xpath, $gallery . '[@multiple]'));
        foreach ([$camera, $gallery, $report . '//input[@data-photo-carrier]'] as $input) {
            // No HEIC (decision Q6): iOS then transcodes the photo itself.
            $this->assertSame('image/jpeg,image/png,image/webp', self::attr($xpath, $input, 'accept'));
        }
        // The pickers post nothing; only the carrier has a name.
        $this->assertSame('', self::attr($xpath, $camera, 'name'));
        $this->assertSame('', self::attr($xpath, $gallery, 'name'));
        $this->assertSame('photos[]', self::attr($xpath, $report . '//input[@data-photo-carrier]', 'name'));
        // The report waits for « Ajouter »: it is not sent on a pick.
        $this->assertSame(0, self::nodes($xpath, $report . '[@data-photo-autosubmit]'));
    }

    public function testTheReportFormSaysWhatToPhotographAndTheLimit(): void
    {
        $xpath = $this->renderIncidents(true);

        $this->assertStringContainsString(
            "Une vue d'ensemble, puis un gros plan.",
            $xpath->query('//*[@data-incident-photo-help]')?->item(0)?->textContent ?? ''
        );
        $report = $xpath->query('//form[@action="/mes-locations/incident"]')?->item(0)?->textContent ?? '';
        $this->assertStringContainsString("Jusqu'à 5 photos.", $report);
        $this->assertStringContainsString('Photographiez le dégât, pas les personnes.', $report);
    }

    public function testOnAPhoneTheAmountHasALineOfItsOwnAndThePhotosComeAfter(): void
    {
        $xpath = $this->renderIncidents(true);
        $columns = $xpath->query('//form[@action="/mes-locations/incident"]/div');
        $this->assertNotFalse($columns);

        $classes = [];
        foreach ($columns as $column) {
            \assert($column instanceof \DOMElement);
            $classes[] = $column->getAttribute('class');
        }
        $this->assertStringStartsWith('col-12 ', $classes[0]);
        $this->assertStringStartsWith('col-12 ', $classes[1]);
        $this->assertSame('col-12', $classes[2]);
        $this->assertSame(1, self::nodes($xpath, '//form[@action="/mes-locations/incident"]/div[3]//*[@data-photo-tile]'));
    }

    public function testAnObservedIncidentShowsItsThumbnailsWithACrossBehindAQuestion(): void
    {
        $xpath = $this->renderIncidents(true, $this->incident(4, 2));
        $item = '//li[@id="incident-4"]';

        $this->assertSame('/files/401/thumb', self::attr($xpath, $item . '//figure[1]//img', 'src'));
        $this->assertSame('/files/402/thumb', self::attr($xpath, $item . '//figure[2]//img', 'src'));
        $this->assertSame('Photo 1', self::attr($xpath, $item . '//figure[1]//img', 'alt'));
        // The full photo opens in the viewer, never as a bare navigation.
        $this->assertSame('/files/401', self::attr($xpath, $item . '//figure[1]/a', 'data-file-viewer'));

        $cross = $item . '//figure[2]//button[contains(@class, "photo-thumb-remove")]';
        $this->assertSame('incident-photo-remove-42', self::attr($xpath, $cross, 'form'));
        $remove = '//form[@id="incident-photo-remove-42"]';
        $this->assertSame('/mes-locations/incident/photo/retirer', self::attr($xpath, $remove, 'action'));
        $this->assertStringStartsWith('Retirer la photo 2 de cet incident ?', self::attr($xpath, $remove, 'data-confirm'));
        $this->assertSame('42', self::attr($xpath, $remove . '//input[@name="photo_id"]', 'value'));
        $this->assertSame('9', self::attr($xpath, $remove . '//input[@name="booking_id"]', 'value'));
    }

    public function testAPhotoPickedForAnObservedIncidentIsSentAtOnceThroughTheAddRoute(): void
    {
        $xpath = $this->renderIncidents(true, $this->incident(4, 2));
        $add = '//li[@id="incident-4"]//form[@action="/mes-locations/incident/photo"]';

        $this->assertSame(1, self::nodes($xpath, $add . '[@data-photo-autosubmit]'));
        $this->assertSame('4', self::attr($xpath, $add . '//input[@name="incident_id"]', 'value'));
        $this->assertSame('2', self::attr($xpath, $add . '//*[@data-photo-tile]', 'data-photo-attached'));
        $this->assertSame('photos[]', self::attr($xpath, $add . '//input[@data-photo-carrier]', 'name'));
    }

    public function testAnIncidentWithFivePhotosOffersNoTile(): void
    {
        $xpath = $this->renderIncidents(true, $this->incident(4, 5));
        $item = '//li[@id="incident-4"]';

        $this->assertSame(5, self::nodes($xpath, $item . '//figure'));
        $this->assertSame(0, self::nodes($xpath, $item . '//*[@data-photo-add]'));
        $this->assertSame(0, self::nodes($xpath, $item . '//input[@type="file"]'));
        // Each one can still be removed.
        $this->assertSame(5, self::nodes($xpath, $item . '//button[contains(@class, "photo-thumb-remove")]'));
    }

    public function testOnceTheDepartureIsValidatedThePhotosAreShownAndNothingElse(): void
    {
        $xpath = $this->renderIncidents(false, $this->incident(4, 2));
        $item = '//li[@id="incident-4"]';

        $this->assertSame(2, self::nodes($xpath, $item . '//figure'));
        $this->assertSame(0, self::nodes($xpath, '//form[contains(@action, "/photo")]'));
        $this->assertSame(0, self::nodes($xpath, '//button[contains(@class, "photo-thumb-remove")]'));
        $this->assertSame(0, self::nodes($xpath, '//*[@data-photo-add]'));
        $this->assertSame(0, self::nodes($xpath, '//form[@action="/mes-locations/incident"]'));
    }

    public function testAnIncidentWithoutAPhotoAfterTheDepartureShowsNoEmptyRow(): void
    {
        $xpath = $this->renderIncidents(false, $this->incident(4, 0));

        $this->assertSame(0, self::nodes($xpath, '//*[@data-photo-tile]'));
    }

    private function renderMeters(?MeterReading $arrival): \DOMXPath
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);
        $meter = new RentalMeter(6, 3, 'Électricité', MeterKind::ELECTRICITY, 'kWh', null);

        return self::xpath($twig->render('@rental/management/_inventory_meters.html.twig', [
            'consumptions' => [MeterConsumption::of($meter, $arrival, null, null)],
            'phases' => [ReadingPhase::ARRIVAL],
            'asset' => ['id' => 3],
            'booking' => ['id' => 9],
        ]));
    }

    private static function reading(?int $fileId): MeterReading
    {
        return new MeterReading(
            1,
            9,
            6,
            ReadingPhase::ARRIVAL,
            1_234_000,
            new \DateTimeImmutable('2027-07-20 10:00:00'),
            $fileId,
            null,
            null
        );
    }

    public function testAReadingTakesOnePhotoThroughTheTile(): void
    {
        $xpath = $this->renderMeters(null);
        $form = '//form[@action="/mes-locations/releve"]';

        $this->assertSame('1', self::attr($xpath, $form . '//*[@data-photo-tile]', 'data-photo-max'));
        $this->assertSame('photo', self::attr($xpath, $form . '//input[@data-photo-carrier]', 'name'));
        $this->assertSame(0, self::nodes($xpath, $form . '//input[@data-photo-carrier][@multiple]'));
        $this->assertSame(0, self::nodes($xpath, $form . '//input[@data-photo-source="gallery"][@multiple]'));
        $this->assertSame('environment', self::attr($xpath, $form . '//input[@data-photo-source="camera"]', 'capture'));
        $this->assertSame(0, self::nodes($xpath, '//form[@action="/mes-locations/releve/photo/retirer"]'));
    }

    public function testAReadingWithItsPhotoShowsItWithACrossAndNoTile(): void
    {
        $xpath = $this->renderMeters(self::reading(77));
        $form = '//form[@action="/mes-locations/releve"]';

        $this->assertSame('/files/77/thumb', self::attr($xpath, $form . '//figure//img', 'src'));
        $this->assertSame(0, self::nodes($xpath, $form . '//*[@data-photo-add]'));

        $removeId = self::attr($xpath, $form . '//button[contains(@class, "photo-thumb-remove")]', 'form');
        $remove = '//form[@id="' . $removeId . '"]';
        $this->assertSame('/mes-locations/releve/photo/retirer', self::attr($xpath, $remove, 'action'));
        $this->assertStringStartsWith('Retirer la photo de ce relevé ?', self::attr($xpath, $remove, 'data-confirm'));
        $this->assertSame('6', self::attr($xpath, $remove . '//input[@name="meter_id"]', 'value'));
        $this->assertSame('arrival', self::attr($xpath, $remove . '//input[@name="phase"]', 'value'));
    }

    public function testNoPhotoIsEverEmbeddedInThePage(): void
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);
        $html = $twig->render('@rental/management/_incidents.html.twig', [
            'incidents' => [$this->incident(4, 3)],
            'incident_decisions' => IncidentDecision::decidable(),
            'incidents_open' => true,
            'max_incident_photos' => RentalStayService::MAX_INCIDENT_PHOTOS,
            'asset' => ['id' => 3],
            'booking' => ['id' => 9],
        ]);

        $this->assertStringNotContainsString('data:image', $html);
    }
}
