<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Where one modification of a booking stands (#927, chantier §2.3).
 *
 * Two roads, one history. Before the first contract is sent a change is
 * applied at once (`APPLIED_DIRECTLY`); after it, it is a *project* —
 * `DRAFT` while the manager prepares it, `SENT` once its replacement
 * contract went out (its terms frozen from then on), and `APPLIED` the
 * moment that contract is countersigned. `CANCELLED` ends a project
 * without touching the booking.
 */
enum ModificationStatus: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case APPLIED = 'applied';
    case APPLIED_DIRECTLY = 'applied_directly';
    case CANCELLED = 'cancelled';

    /** The « Statut » column of the history. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'En préparation',
            self::SENT => 'Contrat envoyé',
            self::APPLIED => 'Appliquée',
            self::APPLIED_DIRECTLY => 'Appliquée directement',
            self::CANCELLED => 'Annulée',
        };
    }

    /**
     * Whether this is the booking's project in progress — the one row the
     * database lets a booking hold at a time.
     */
    public function isActive(): bool
    {
        return $this === self::DRAFT || $this === self::SENT;
    }

    /** Whether its terms are on the booking now. */
    public function isApplied(): bool
    {
        return $this === self::APPLIED || $this === self::APPLIED_DIRECTLY;
    }
}
