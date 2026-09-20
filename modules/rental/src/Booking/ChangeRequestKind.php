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
    case DATES_AND_PERSONS = 'dates_and_persons';
    case CANCELLATION = 'cancellation';

    public function label(): string
    {
        return match ($this) {
            self::DATES => 'Changement de dates',
            self::PERSONS => 'Changement du nombre de participants',
            self::DATES_AND_PERSONS => 'Changement de dates et de participants',
            self::CANCELLATION => 'Demande d\'annulation',
        };
    }

    /**
     * What a renter's edit turns out to be, or **null** when nothing on the
     * form differs from the booking.
     *
     * The renter no longer picks a type: they edit their booking's own
     * values and the type follows from what moved. `rental_change_requests`
     * has always carried `arrival`, `departure`, `units` and `persons` on
     * **one** row — only `kind` forbade combining them, which made
     * "different dates AND a smaller group" two requests a manager had to
     * answer separately, each valid only if the other was accepted too.
     *
     * Null rather than a fourth case, because "nothing changed" is not a
     * kind of request: it is a form to refuse, and the caller says so in
     * French.
     */
    public static function forChange(bool $datesChanged, bool $personsChanged): ?self
    {
        return match (true) {
            $datesChanged && $personsChanged => self::DATES_AND_PERSONS,
            $datesChanged => self::DATES,
            $personsChanged => self::PERSONS,
            default => null,
        };
    }

    /**
     * Whether accepting this changes what the booking occupies, and
     * therefore has to pass the availability check again.
     *
     * "Dates are present", not "this is the dates-only kind": a request
     * that moves the dates *and* the group still moves the dates, and
     * reading it as a participants change would let it through the one
     * check that protects the calendar.
     */
    public function affectsAvailability(): bool
    {
        return $this === self::DATES || $this === self::DATES_AND_PERSONS;
    }
}
