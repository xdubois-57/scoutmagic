<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Api;

/**
 * A camp site as another site described it, handed to
 * {@see PlaceImportWriterInterface}.
 *
 * Every value is as the source gave it, untrimmed and unchecked: what fits
 * a place is the camps module's to decide, and it says which values it
 * would leave out with {@see PlaceImportWriterInterface::unrecognisedFields()}.
 * The coordinates are text, the way a form carries them, so that the
 * module reads them with the very rule its place form uses (both or
 * neither, within bounds).
 */
final class ImportedPlace
{
    /** camp_places.name is a VARCHAR(150). */
    public const MAX_NAME_LENGTH = 150;

    public function __construct(
        public readonly string $name,
        public readonly ?string $address = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly ?string $websiteUrl = null,
        public readonly ?string $latitude = null,
        public readonly ?string $longitude = null,
        public readonly bool $isArchived = false
    ) {
    }
}
