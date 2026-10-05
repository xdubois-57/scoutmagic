<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * Which end of the stay an observation belongs to — a meter reading or an
 * inventory line (§6.22, §6.23).
 */
enum ReadingPhase: string
{
    case ARRIVAL = 'arrival';
    case DEPARTURE = 'departure';

    public function label(): string
    {
        return match ($this) {
            self::ARRIVAL => "Entrée",
            self::DEPARTURE => 'Sortie',
        };
    }

    public function other(): self
    {
        return $this === self::ARRIVAL ? self::DEPARTURE : self::ARRIVAL;
    }

    /**
     * The phases whose validation freezes this one (#708, IT-17): its own,
     * and every later one. The departure is read against the arrival, so
     * an arrival only ticked by hand — never validated itself — is frozen
     * all the same once the departure's PDF has gone out.
     *
     * @return list<self>
     */
    public function frozenBy(): array
    {
        return $this === self::ARRIVAL ? [self::ARRIVAL, self::DEPARTURE] : [self::DEPARTURE];
    }
}
