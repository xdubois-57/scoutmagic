<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * How much a signed inventory walked (#989, lot G), stored as
 * `rental_inventory_validations.scope`.
 *
 * FULL is the checklist and the meters the booking keeps, line by line.
 * MINIMAL is the inventory of an asset that keeps neither: one statement
 * — the fixed sentence, or the manager's free remark — and, at the
 * departure, the incidents noted. It replaced the dashboard's bare tick:
 * signed the same way, on the same screen, and sent the same way, so an
 * inventory is never « done » without a PDF the renter holds.
 */
enum InventoryScope: string
{
    case FULL = 'full';
    case MINIMAL = 'minimal';

    /** The longest free remark a minimal inventory keeps. */
    public const REMARK_MAX = 2000;

    /**
     * The statement a minimal inventory makes when the manager wrote no
     * remark: the asset handed over (arrival) or given back (departure)
     * in good order — or, given back with incidents noted, given back
     * with the incidents listed below it, never « en bon état ».
     */
    public static function fixedStatement(ReadingPhase $phase, bool $withIncidents = false): string
    {
        if ($phase === ReadingPhase::ARRIVAL) {
            return 'Bien remis en bon état, sans remarque';
        }

        return $withIncidents
            ? 'Bien rendu ; incidents constatés ci-dessous'
            : 'Bien rendu en bon état, sans remarque';
    }

    /**
     * What a minimal inventory says: the remark when there is one, the
     * fixed sentence otherwise. `$withIncidents` is whether a departure
     * lists incidents.
     */
    public static function statement(ReadingPhase $phase, ?string $remark, bool $withIncidents = false): string
    {
        return $remark !== null && trim($remark) !== ''
            ? trim($remark)
            : self::fixedStatement($phase, $withIncidents);
    }
}
