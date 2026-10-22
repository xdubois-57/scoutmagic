<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * One photo printed in the annex of an inventory's PDF (#989): its number
 * there, what it shows — an incident, or a meter at one end of the stay —
 * and the JPEG embedded, a 1 600 px copy made for this PDF alone
 * (`Service\InventoryPhotoRenderer`) and never stored.
 *
 * Built by `InventoryPhotoAnnex::photos()`, which alone numbers them, so
 * the number an incident line refers to (« photos 3 et 4 ») and the one
 * under the picture are the same by construction.
 */
final class InventoryPhoto
{
    public function __construct(
        /** From 1, in the annex's order. */
        public readonly int $number,
        public readonly int $fileId,
        /** What the caption names: the incident's description, or the meter's label. */
        public readonly string $subject,
        /** The incident it illustrates, or null for a meter's. */
        public readonly ?int $incidentId,
        /** The meter it shows, or null for an incident's. */
        public readonly ?int $meterId,
        /** The end of the stay a meter's photo was taken at, or null for an incident's. */
        public readonly ?ReadingPhase $phase,
        /** The reading, as printed (« 1 234,567 kWh »), or null for an incident's. */
        public readonly ?string $meterValue,
        /** When the reading was taken or the photo added to the incident. */
        public readonly \DateTimeImmutable $takenAt,
        /** The JPEG embedded, bytes. */
        public readonly string $jpeg,
        public readonly int $width,
        public readonly int $height
    ) {
    }

    public function isOfAMeter(): bool
    {
        return $this->meterId !== null;
    }
}
