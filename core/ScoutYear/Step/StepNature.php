<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step;

/**
 * Who says a step is done.
 *
 * A HUMAN step is done when somebody marked it, and only then: its checks
 * produce points to check, never a « done ». An OBSERVED step is done when
 * the site sees it is (an import on record, a year setting) and can never
 * be marked by hand — ticking « activé pour tout le monde » would be a lie
 * the site is in a position to catch.
 */
enum StepNature: string
{
    case HUMAN = 'human';
    case OBSERVED = 'observed';

    /** The French tag the page shows; there is no module tag. */
    public function label(): string
    {
        return match ($this) {
            self::HUMAN => 'Décision humaine',
            self::OBSERVED => 'Constaté par le site',
        };
    }
}
