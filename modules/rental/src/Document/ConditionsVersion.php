<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

/**
 * One archived wording of an asset's conditions (issue #494).
 *
 * Written once and never changed: this is the text a renter who accepted
 * `$version` was shown, and the page at `/locations/{slug}/conditions/{version}`
 * serves it as it was.
 */
final class ConditionsVersion
{
    public function __construct(
        public readonly int $assetId,
        /** The first twelve characters of `$hash` — what a booking stores. */
        public readonly string $version,
        /** `RentalBookingService::hashAcceptedText()` of `$html`. */
        public readonly string $hash,
        /** Sanitized rich text, exactly as it was shown. */
        public readonly string $html,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }
}
