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
}
