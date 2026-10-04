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

    /**
     * The date this version can be named by for a renter who accepted it
     * at `$acceptedAt`, or null when it has none to give.
     *
     * `$createdAt` is when the archive first held the text, not when the
     * text went live: a wording older than the archive is archived on the
     * first read after the upgrade, and carries that read's date. A version
     * archived after the renter accepted it would therefore be dated after
     * the acceptance — callers then say when it was accepted instead.
     */
    public function dateKnownAt(?\DateTimeImmutable $acceptedAt): ?\DateTimeImmutable
    {
        return $acceptedAt !== null && $this->createdAt > $acceptedAt ? null : $this->createdAt;
    }
}
