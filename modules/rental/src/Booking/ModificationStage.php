<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Booking;

/**
 * Where a modification project in progress stands, as a manager reads it
 * (#927, chantier §2.3 and §2.4) — derived from the project and the copies
 * filed against its replacement contract, never stored.
 *
 * The cycle: prepared, its contract sent, a copy back, countersigned —
 * and a copy refused sends it back to waiting for another copy of the
 * SAME contract. Countersigning applies it, which ends the cycle; so does
 * a cancellation.
 */
enum ModificationStage: string
{
    case DRAFT = 'draft';
    case AWAITING_COPY = 'awaiting_copy';
    case COPY_RECEIVED = 'copy_received';
    case COPY_REFUSED = 'copy_refused';

    /**
     * @param bool $copyPending a copy of its contract waits for an answer
     * @param bool $copyRefused the last copy of its contract was refused
     */
    public static function of(BookingModification $modification, bool $copyPending, bool $copyRefused): ?self
    {
        if (!$modification->isActive()) {
            return null;
        }
        if ($modification->status === ModificationStatus::DRAFT) {
            return self::DRAFT;
        }
        if ($copyPending) {
            return self::COPY_RECEIVED;
        }

        return $copyRefused ? self::COPY_REFUSED : self::AWAITING_COPY;
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'En préparation',
            self::AWAITING_COPY => 'Copie signée attendue',
            self::COPY_RECEIVED => 'Copie reçue à vérifier',
            self::COPY_REFUSED => 'Copie refusée — nouvelle copie attendue',
        };
    }

    /** The badge's tone: `partials/status_badge.html.twig` reads a status key. */
    public function badgeStatus(): string
    {
        return match ($this) {
            self::DRAFT => 'draft',
            self::AWAITING_COPY, self::COPY_RECEIVED => 'pending',
            self::COPY_REFUSED => 'refused',
        };
    }

    /** « Prochaine étape : … » on the Modifications page. */
    public function nextStep(): string
    {
        return match ($this) {
            self::DRAFT => 'Générer puis envoyer le contrat de remplacement',
            self::AWAITING_COPY => 'Attendre la copie signée du locataire',
            self::COPY_RECEIVED => 'Vérifier la copie, puis contresigner',
            self::COPY_REFUSED => 'Attendre une nouvelle copie du même contrat',
        };
    }

    /** The heading of « Prochaine action » on the dashboard while it runs. */
    public function headline(): string
    {
        return match ($this) {
            self::DRAFT => 'Modification en cours : envoyer le contrat de remplacement',
            self::AWAITING_COPY => 'Modification en cours : copie signée du nouveau contrat attendue',
            self::COPY_RECEIVED => 'Modification en cours : vérifier la copie signée et contresigner',
            self::COPY_REFUSED => 'Modification en cours : nouvelle copie attendue (la précédente a été refusée)',
        };
    }

    /** Whether the next move is the unit's: preparing, or answering a copy. */
    public function waitsOnTheUnit(): bool
    {
        return $this === self::DRAFT || $this === self::COPY_RECEIVED;
    }
}
