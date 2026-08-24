<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * One address of one person, as Desk exports it.
 *
 * `type` is the "Type d'adresse" column and is the deduplication key:
 * DeskCsvParser::buildMember() keeps the FIRST row it sees per type and
 * ignores later ones, so two addresses of the same person must never share a
 * type or one of them silently disappears.
 */
final class PostalAddress
{
    public function __construct(
        public readonly string $type,
        public readonly string $street,
        public readonly string $number,
        public readonly ?string $box,
        public readonly ?string $complement,
        public readonly string $postalCode,
        public readonly string $city,
        public readonly string $country = 'Belgique',
    ) {
    }
}
