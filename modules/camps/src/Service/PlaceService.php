<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Geo\GeoPoint;
use Core\Geo\PointSubmission;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Support;

/**
 * Camp sites: creation, edition, and the change history that goes with
 * them (Core\Audit entity type 'camp_place').
 */
class PlaceService
{
    public const ENTITY_TYPE = 'camp_place';

    public function __construct(
        private PlaceRepository $places,
        private AuditService $audit
    ) {
    }

    /**
     * @param array<string, string|null> $fields name/address/postal_code/city/country/website_url
     */
    public function create(array $fields, ?int $actorUserAccountId, AuditSource $source = AuditSource::Human): int
    {
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            throw new CampsException('Un lieu a besoin d\'un nom.');
        }

        $id = $this->places->create(
            $name,
            Support::clean($fields['address'] ?? null),
            Support::clean($fields['postal_code'] ?? null),
            Support::clean($fields['city'] ?? null),
            Support::clean($fields['country'] ?? null),
            $this->cleanWebsiteUrl($fields['website_url'] ?? null),
        );

        $this->audit->record(
            self::ENTITY_TYPE,
            $id,
            'name',
            null,
            $name,
            $source,
            'Lieu créé',
            null,
            $actorUserAccountId
        );

        return $id;
    }

    /**
     * @param array<string, string|null> $fields
     */
    public function update(
        Place $place,
        array $fields,
        ?int $actorUserAccountId,
        AuditSource $source = AuditSource::Human
    ): void
    {
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            throw new CampsException('Un lieu a besoin d\'un nom.');
        }

        $address = Support::clean($fields['address'] ?? null);
        $postalCode = Support::clean($fields['postal_code'] ?? null);
        $city = Support::clean($fields['city'] ?? null);
        $country = Support::clean($fields['country'] ?? null);
        $websiteUrl = $this->cleanWebsiteUrl($fields['website_url'] ?? null);

        $this->places->update($place->id, $name, $address, $postalCode, $city, $country, $websiteUrl);

        // A changed address is a different place on the map. `geocoded_at`
        // means "we have tried this address", so leaving it stamped kept
        // the pin the OLD address produced for ever — the map went on
        // showing the wrong field, and nothing ever asked again. Cleared
        // only for an automatically-placed pin: the repository's own
        // `coordinates_are_manual = 0` fence keeps a hand-placed one.
        $newLine = $this->addressLine($address, $postalCode, $city, $country);
        $addressChanged = $this->addressLine($place->address, $place->postalCode, $place->city, $place->country)
            !== $newLine;
        if ($addressChanged) {
            $this->places->clearGeocoding($place->id);
        }

        // Coordinates are their own decision, taken only when the form
        // actually carried them: a chief editing an address must not
        // silently wipe a pin somebody placed by hand.
        if (array_key_exists('latitude', $fields) || array_key_exists('longitude', $fields)) {
            $this->updateCoordinates($place, $fields, (string) $newLine, $addressChanged, $source, $actorUserAccountId);
        }

        // Recorded field by field rather than as one "lieu modifié": a
        // history is read to find out what a value used to be, which a
        // single line naming none of them cannot answer.
        $this->recordChange($place->id, 'name', $place->name, $name, $source, $actorUserAccountId);
        $this->recordChange(
            $place->id,
            'address',
            $this->addressLine($place->address, $place->postalCode, $place->city, $place->country),
            $this->addressLine($address, $postalCode, $city, $country),
            $source,
            $actorUserAccountId
        );
        $this->recordChange($place->id, 'website', $place->websiteUrl, $websiteUrl, $source, $actorUserAccountId);
    }

    /**
     * The point the form carried — read by the core's rules
     * (`Core\Geo\PointSubmission`, #1353), which the place form's point
     * picker shares with every other form that places something on a map.
     *
     * Placed, moved, typed or removed by a human: marked manual FOR EVER,
     * and automatic geocoding never touches the place again. Somebody who
     * moved the pin onto the actual field knows something Nominatim does
     * not, and the whole feature is worthless if the next task run puts it
     * back on the village square.
     *
     * Found from the address and left alone: automatic, exactly as the
     * background task would have stored it — a point the chief only looked
     * at must not freeze the place. One found for another address than the
     * one posted (sent before the new lookup answered) is no point at all,
     * and the task finds the right one.
     *
     * @param array<string, string|null> $fields
     */
    private function updateCoordinates(
        Place $place,
        array $fields,
        string $addressLine,
        bool $addressChanged,
        AuditSource $source,
        ?int $actorUserAccountId
    ): void {
        // The core parses and refuses (GeoPointException, user-facing):
        // the same inputs are refused with the same sentences on every
        // form that places something on the map.
        $submission = PointSubmission::fromInput($fields, $addressLine);
        $saved = GeoPoint::fromColumns($place->latitude, $place->longitude);
        if (!$submission->changes($saved, $place->coordinatesAreManual, $addressChanged)) {
            return;
        }

        $point = $submission->point;
        if ($submission->automatic) {
            if ($point === null) {
                return;
            }
            $this->places->recordGeocoding($place->id, $point->latitude, $point->longitude, new \DateTimeImmutable());
            $this->audit->record(
                self::ENTITY_TYPE,
                $place->id,
                'coordinates',
                $saved?->line(),
                $point->line(),
                $source,
                'Coordonnées trouvées à partir de l\'adresse',
                null,
                $actorUserAccountId
            );

            return;
        }

        $this->places->setManualCoordinates($place->id, $point?->latitude, $point?->longitude);
        $this->audit->record(
            self::ENTITY_TYPE,
            $place->id,
            'coordinates',
            $saved?->line(),
            $point?->line(),
            $source,
            'Coordonnées saisies à la main — le géocodage automatique ne touchera plus ce lieu',
            null,
            $actorUserAccountId
        );
    }

    /**
     * The address as one readable line, which is also how the history
     * shows it: five separate entries for one address correction is
     * noise, and the reader wants "what was the address before".
     */
    public function addressLine(?string $address, ?string $postalCode, ?string $city, ?string $country): ?string
    {
        $parts = array_values(array_filter([
            $address,
            trim(($postalCode ?? '') . ' ' . ($city ?? '')),
            $country,
        ], static fn(?string $p): bool => $p !== null && trim($p) !== ''));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function recordChange(
        int $placeId,
        string $fieldKey,
        ?string $from,
        ?string $to,
        AuditSource $source,
        ?int $actorUserAccountId
    ): void {
        if ($from === $to) {
            return;
        }

        $this->audit->record(
            self::ENTITY_TYPE,
            $placeId,
            $fieldKey,
            $from,
            $to,
            $source,
            null,
            null,
            $actorUserAccountId
        );
    }

    /**
     * A website that is not http(s) is refused rather than stored and
     * rendered as a link — a "javascript:" in an href is the whole reason
     * this check exists, and a place sheet renders this value as one.
     * Public for Service\PlaceImportWriter, which leaves out what this
     * refuses instead of refusing the whole place.
     *
     * @throws CampsException
     */
    public function cleanWebsiteUrl(?string $value): ?string
    {
        $value = Support::clean($value);
        if ($value === null) {
            return null;
        }
        // Only a MISSING scheme gets https. Prefixing one that is
        // already there would turn "file:///etc/passwd" into
        // "https://file:///etc/passwd", which parses as https and passes
        // the check below — see Service\LinkService::validateUrl(), which
        // had exactly this hole.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) !== 1) {
            $value = 'https://' . $value;
        }
        if (!in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new CampsException('L\'adresse du site web doit commencer par http:// ou https://.');
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new CampsException('L\'adresse du site web n\'est pas une adresse valide.');
        }

        return $value;
    }
}
