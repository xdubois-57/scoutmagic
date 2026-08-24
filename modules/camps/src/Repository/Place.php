<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Repository;

/**
 * A camp site (schema.sql: camp_places). Every field is in clear — a
 * place is a plot of land, not a natural person; the people attached to
 * it are camp_contacts, and those are encrypted.
 */
class Place
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $country,
        public readonly ?string $websiteUrl,
        public readonly bool $isArchived,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly bool $coordinatesAreManual,
        public readonly ?string $geocodedAt,
        public readonly ?string $aiSummary,
        public readonly ?string $aiSummaryGeneratedAt,
        public readonly bool $aiSummaryIsStale,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
    }

    /** Whether this place can appear on the map at all. */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * "5340 Mozet" — the one-line locality used under a place's name
     * everywhere it is listed. Empty when neither is known, which is a
     * real case (a field with coordinates and nothing else).
     */
    public function locality(): string
    {
        return trim(($this->postalCode ?? '') . ' ' . ($this->city ?? ''));
    }
}
