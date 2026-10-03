<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * How a milestone gets ticked (issue #462, D6).
 *
 * A ticked line used to say nothing about how it came to be ticked, so a
 * manager could not tell a line that fills itself in from one waiting on
 * them, on the renter, or on something no screen will ever see. Each
 * nature has its own shape on the journey, and the shape is the point:
 *
 * | Nature   | What the line shows                                   |
 * |----------|-------------------------------------------------------|
 * | DERIVED  | the derived sentence, and no control at all           |
 * | HERE     | a button, and the other decisions beside it           |
 * | RENTER   | the derived sentence (no manual chase exists to offer) |
 * | OFFSITE  | nothing the site can see: only ticked by hand         |
 *
 * **Any nature can be completed by hand since #708 (IT-14)**, from the
 * step's disc: things happen away from the site, a contract accepted by
 * e-mail, a deposit paid in cash. The nature still says how the line
 * normally gets ticked; OFFSITE is the one whose only way is the hand.
 */
enum MilestoneKind: string
{
    case DERIVED = 'derived';
    case HERE = 'here';
    case RENTER = 'renter';
    case OFFSITE = 'offsite';

    public function label(): string
    {
        return match ($this) {
            self::DERIVED => 'se dérive du site',
            self::HERE => 'à faire ici',
            self::RENTER => 'en attente du locataire',
            self::OFFSITE => 'hors du site',
        };
    }

    /**
     * Whether a manager can tick this line by hand.
     */
    public function isMarkable(): bool
    {
        return $this === self::OFFSITE;
    }
}
