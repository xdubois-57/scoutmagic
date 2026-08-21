<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What a meter measures (§6.22).
 *
 * **Presentation only.** The arithmetic is identical for all of them —
 * departure minus arrival, times a unit price — and inventing a
 * kind-specific calculation is how a module ends up unable to meter
 * something nobody thought of. The kind picks an icon and a default unit,
 * and that is the whole of its job.
 */
enum MeterKind: string
{
    case ELECTRICITY = 'electricity';
    case GAS = 'gas';
    case WATER = 'water';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ELECTRICITY => 'Électricité',
            self::GAS => 'Gaz',
            self::WATER => 'Eau',
            self::OTHER => 'Autre',
        };
    }

    /** The unit an operator most likely wants, pre-filled and editable. */
    public function defaultUnit(): string
    {
        return match ($this) {
            self::ELECTRICITY => 'kWh',
            self::GAS, self::WATER => 'm³',
            self::OTHER => '',
        };
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }
}
