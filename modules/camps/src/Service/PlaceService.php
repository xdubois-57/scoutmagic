<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
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
            $this->cleanUrl($fields['website_url'] ?? null),
        );

        $this->audit->record(
            self::ENTITY_TYPE, $id, 'name', null, $name, $source,
            'Lieu créé', null, $actorUserAccountId
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
        $websiteUrl = $this->cleanUrl($fields['website_url'] ?? null);

        $this->places->update($place->id, $name, $address, $postalCode, $city, $country, $websiteUrl);

        // A changed address is a different place on the map. `geocoded_at`
        // means "we have tried this address", so leaving it stamped kept
        // the pin the OLD address produced for ever — the map went on
        // showing the wrong field, and nothing ever asked again. Cleared
        // only for an automatically-placed pin: the repository's own
        // `coordinates_are_manual = 0` fence keeps a hand-placed one.
        if ($this->addressLine($place->address, $place->postalCode, $place->city, $place->country)
            !== $this->addressLine($address, $postalCode, $city, $country)
        ) {
            $this->places->clearGeocoding($place->id);
        }

        // Coordinates are their own decision, taken only when the form
        // actually carried them: a chief editing an address must not
        // silently wipe a pin somebody placed by hand.
        if (array_key_exists('latitude', $fields) || array_key_exists('longitude', $fields)) {
            $this->updateCoordinates($place, $fields, $source, $actorUserAccountId);
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
     * Coordinates typed by a human.
     *
     * Setting them marks the place manual FOR EVER: automatic geocoding
     * never touches it again. Somebody who moved the pin onto the actual
     * field knows something Nominatim does not, and the whole feature is
     * worthless if the next task run puts it back on the village square.
     *
     * @param array<string, string|null> $fields
     */
    private function updateCoordinates(Place $place, array $fields, AuditSource $source, ?int $actorUserAccountId): void
    {
        $latitude = $this->cleanCoordinate($fields['latitude'] ?? null, -90.0, 90.0, 'latitude');
        $longitude = $this->cleanCoordinate($fields['longitude'] ?? null, -180.0, 180.0, 'longitude');

        if (($latitude === null) !== ($longitude === null)) {
            throw new CampsException(
                'Indiquez la latitude ET la longitude, ou laissez les deux vides — un point a besoin des deux.'
            );
        }

        $before = $this->coordinateLine($place->latitude, $place->longitude);
        $after = $this->coordinateLine($latitude, $longitude);
        if ($before === $after) {
            return;
        }

        $this->places->setManualCoordinates($place->id, $latitude, $longitude);
        $this->audit->record(
            self::ENTITY_TYPE, $place->id, 'coordinates', $before, $after, $source,
            'Coordonnées saisies à la main — le géocodage automatique ne touchera plus ce lieu',
            null, $actorUserAccountId
        );
    }

    private function cleanCoordinate(?string $value, float $min, float $max, string $label): ?float
    {
        $value = $value !== null ? trim(str_replace(',', '.', $value)) : '';
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new CampsException("La {$label} doit être un nombre, par exemple 50.443210.");
        }

        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new CampsException("La {$label} doit être comprise entre {$min} et {$max}.");
        }

        return $number;
    }

    private function coordinateLine(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return number_format($latitude, 6, '.', '') . ', ' . number_format($longitude, 6, '.', '');
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

        $this->audit->record(self::ENTITY_TYPE, $placeId, $fieldKey, $from, $to, $source, null, null,
            $actorUserAccountId);
    }

    /**
     * A website that is not http(s) is refused rather than stored and
     * rendered as a link — a "javascript:" in an href is the whole reason
     * this check exists, and a place sheet renders this value as one.
     */
    private function cleanUrl(?string $value): ?string
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
