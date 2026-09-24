<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * A point on the map: a latitude and a longitude, always both.
 *
 * The coordinates half of the pair the core owns since the carpool chantier
 * (docs/chantiers/covoiturage.md, IT-02) — the other half is the manual
 * lock, enforced by GeoPointStore. Parsing what a human typed lives here so
 * that the camps place form and the carpool form refuse the same inputs
 * with the same sentences.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude
    ) {
    }

    /**
     * What a form carried, as a point — or null when both boxes were left
     * blank, which is a legitimate answer (« no point »).
     *
     * A comma is accepted as the decimal separator: that is how a Belgian
     * keyboard and a copy from a French map both write it.
     *
     * @throws GeoPointException when only one half was given, or a half is
     *         not a number, or it is out of range
     */
    public static function fromInput(?string $latitude, ?string $longitude): ?self
    {
        $lat = self::parseCoordinate($latitude, -90.0, 90.0, 'latitude');
        $lng = self::parseCoordinate($longitude, -180.0, 180.0, 'longitude');

        if (($lat === null) !== ($lng === null)) {
            throw new GeoPointException(
                'Indiquez la latitude ET la longitude, ou laissez les deux vides — un point a besoin des deux.'
            );
        }

        return $lat !== null && $lng !== null ? new self($lat, $lng) : null;
    }

    /** A point from two nullable columns, or null when either is empty. */
    public static function fromColumns(mixed $latitude, mixed $longitude): ?self
    {
        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return null;
        }

        return new self((float) $latitude, (float) $longitude);
    }

    /**
     * "50.443210, 4.887650" — six decimals, which is the precision the
     * columns store (DECIMAL(9, 6), about ten centimetres) and the way a
     * change history shows it.
     */
    public function line(): string
    {
        return number_format($this->latitude, 6, '.', '') . ', ' . number_format($this->longitude, 6, '.', '');
    }

    private static function parseCoordinate(?string $value, float $min, float $max, string $label): ?float
    {
        $value = $value !== null ? trim(str_replace(',', '.', $value)) : '';
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new GeoPointException("La {$label} doit être un nombre, par exemple 50.443210.");
        }

        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new GeoPointException("La {$label} doit être comprise entre {$min} et {$max}.");
        }

        return $number;
    }
}
