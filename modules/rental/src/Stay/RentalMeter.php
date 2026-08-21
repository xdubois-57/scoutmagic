<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * A meter configured on an asset (§6.22).
 */
final class RentalMeter
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        public readonly string $label,
        public readonly MeterKind $kind,
        public readonly string $unit,
        /** The `meter`-nature fee pricing one unit, or null for "read but do not bill". */
        public readonly ?int $feeId,
        public readonly int $sortOrder = 0,
        public readonly bool $isActive = true
    ) {
    }

    public function displayUnit(): string
    {
        return $this->unit !== '' ? $this->unit : $this->kind->defaultUnit();
    }
}
