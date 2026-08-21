<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Where a booking is in its life (module spec §6.15).
 *
 * **"In progress" is deliberately not one of these.** A stay that is
 * currently happening is derived from the dates, never stored — a stored
 * flag would need a scheduled task to flip it and would spend part of every
 * day disagreeing with the calendar.
 *
 * The spec presents the lifecycle to a manager as a **checklist of
 * milestones**, not as a status badge. This enum is the compact internal
 * machine the checklist is derived from, which is exactly the split the spec
 * allows for.
 */
enum BookingStatus: string
{
    /** A public request has arrived and nobody has looked at it yet. */
    case RECEIVED = 'received';
    case REVIEWING = 'reviewing';
    case INFO_REQUESTED = 'info_requested';
    case PROPOSED = 'proposed';
    case CONFIRMED = 'confirmed';
    case REFUSED = 'refused';
    case CANCELLED = 'cancelled';
    /** A manager's option lapsed without confirmation (§6.14). */
    case EXPIRED = 'expired';
    case CLOSED = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::RECEIVED => 'Demande reçue',
            self::REVIEWING => "En cours d'examen",
            self::INFO_REQUESTED => 'Informations demandées',
            self::PROPOSED => 'Proposition envoyée',
            self::CONFIRMED => 'Confirmée',
            self::REFUSED => 'Refusée',
            self::CANCELLED => 'Annulée',
            self::EXPIRED => 'Expirée',
            self::CLOSED => 'Clôturée',
        };
    }

    /**
     * Whether this booking still holds its dates against everyone else.
     *
     * A refused, cancelled or expired booking releases the period
     * immediately — leaving it held would quietly make an asset look busy
     * for stays that will never happen. A closed one is in the past and
     * still counts, so the calendar shows history rather than gaps.
     */
    public function occupiesTheAsset(): bool
    {
        return match ($this) {
            self::REFUSED, self::CANCELLED, self::EXPIRED => false,
            default => true,
        };
    }

    /**
     * Whether this is a final state, and therefore starts the retention
     * clock. Never `received_at` — a request that sat open for a year must
     * not be purged the moment it closes.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::REFUSED, self::CANCELLED, self::EXPIRED, self::CLOSED => true,
            default => false,
        };
    }

    /**
     * Whether a manager still has something to do about it — what "demandes
     * à traiter" counts on the "Mes locations" page.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::RECEIVED, self::REVIEWING, self::INFO_REQUESTED => true,
            default => false,
        };
    }
}
