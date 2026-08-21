<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * What a change request is about (§6.16, §6.17).
 *
 * Contact details are deliberately **not** one of these: correcting a phone
 * number is not a negotiation, and routing it through an accept/refuse
 * cycle would be ceremony for its own sake. It is applied directly and
 * recorded in the history like any other edit.
 */
enum ChangeRequestKind: string
{
    case DATES = 'dates';
    case PERSONS = 'persons';
    case CANCELLATION = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::DATES => 'Changement de dates',
            self::PERSONS => 'Changement du nombre de participants',
            self::CANCELLATION => 'Demande d\'annulation',
        };
    }

    /**
     * Whether accepting this changes what the booking occupies, and
     * therefore has to pass the availability check again.
     */
    public function affectsAvailability(): bool
    {
        return $this === self::DATES;
    }
}
