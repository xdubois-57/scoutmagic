<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * What one line of an inventory says (§6.23).
 *
 * **`NOT_CHECKED` is a real state, not a placeholder.** "Nobody looked" and
 * "somebody looked and it was fine" are different facts, and conflating
 * them is how a missing set of keys becomes nobody's fault: an inventory
 * that defaults to `ok` quietly certifies things that were never examined.
 */
enum InventoryState: string
{
    case NOT_CHECKED = 'not_checked';
    case OK = 'ok';
    case ISSUE = 'issue';
    case MISSING = 'missing';

    public function label(): string
    {
        return match ($this) {
            self::NOT_CHECKED => 'Non vérifié',
            self::OK => 'Conforme',
            self::ISSUE => 'Problème',
            self::MISSING => 'Manquant',
        };
    }

    /** Whether this line is something a manager still has to look at. */
    public function needsAttention(): bool
    {
        return $this === self::ISSUE || $this === self::MISSING;
    }

    /** Bootstrap contextual class for the badge. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::NOT_CHECKED => 'bg-light text-dark border',
            self::OK => 'bg-success',
            self::ISSUE => 'bg-warning text-dark',
            self::MISSING => 'bg-danger',
        };
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }
}
