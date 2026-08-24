<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Modules\Camps\Repository\Camp;

/**
 * The module's whole vocabulary, in one place.
 *
 * It exists because two readers must agree: the camp's page, and the
 * change history under it. Core\Audit stores what it is given and shows
 * exactly that, so a status recorded as "Confirmé" by a service that
 * spelled it its own way, next to a badge the template spelled another
 * way, would read as two different states of the same camp. Every label
 * and every formatted value on both sides comes from here.
 */
class CampLabels
{
    public const STAY_TYPES = [
        Camp::STAY_GRAND_CAMP => 'Grand camp',
        Camp::STAY_WEEKEND => 'Week-end',
        Camp::STAY_OTHER => 'Autre séjour',
    ];

    public const STATUSES = [
        Camp::STATUS_TO_CONFIRM => 'À confirmer',
        Camp::STATUS_CONFIRMED => 'Confirmé',
        Camp::STATUS_CANCELLED => 'Annulé',
    ];

    /**
     * Business status → the site's shared severity vocabulary
     * (partials/status_badge.html.twig). 'cancelled' maps to the grey
     * 'cancelled' tone, NOT to danger: one severity, one colour, across
     * the whole site — a cancelled stay is a fact with nothing left to do
     * about it, not an error.
     */
    public const STATUS_TONES = [
        Camp::STATUS_TO_CONFIRM => 'pending',
        Camp::STATUS_CONFIRMED => 'confirmed',
        Camp::STATUS_CANCELLED => 'cancelled',
    ];

    /** Machine field keys → the words the change history shows. */
    public const FIELD_LABELS = [
        'place' => 'Lieu',
        'stay_type' => 'Type de séjour',
        'dates' => 'Dates',
        'status' => 'Statut',
        'price' => 'Prix',
        'participants' => 'Participants',
        'booked_by' => 'Réservation faite par',
        'sections' => 'Sections',
        'note' => 'Note',
        'contact' => 'Contact',
        'link' => 'Lien',
        'document' => 'Document',
        'photos' => 'Photos',
        'camp' => 'Séjour',
        'name' => 'Nom',
        'address' => 'Adresse',
        'website' => 'Site web',
    ];

    public static function stayType(string $value): string
    {
        return self::STAY_TYPES[$value] ?? $value;
    }

    public static function status(string $value): string
    {
        return self::STATUSES[$value] ?? $value;
    }

    public static function statusTone(string $value): string
    {
        return self::STATUS_TONES[$value] ?? 'neutral';
    }

    /**
     * "12–19 juillet 2028", "1er mai 2026", "2029".
     *
     * An en dash, and the month written once when both ends share it —
     * this string is the camp's headline on every screen it appears on,
     * and "12/07/2028 - 19/07/2028" reads like a database export.
     */
    public static function dateRange(?string $startDate, ?string $endDate, ?int $yearOnly): string
    {
        if ($startDate === null || $endDate === null) {
            return $yearOnly !== null ? (string) $yearOnly : '';
        }

        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return self::longDate($start);
        }
        if ($start->format('Y-m') === $end->format('Y-m')) {
            return self::dayNumber($start) . '–' . self::longDate($end);
        }
        if ($start->format('Y') === $end->format('Y')) {
            return self::dayNumber($start) . ' ' . self::month($start) . ' – ' . self::longDate($end);
        }

        return self::longDate($start) . ' – ' . self::longDate($end);
    }

    public static function money(?int $cents): ?string
    {
        return $cents !== null ? number_format($cents / 100, 2, ',', ' ') . ' €' : null;
    }

    private static function longDate(\DateTimeImmutable $date): string
    {
        return self::dayNumber($date) . ' ' . self::month($date) . ' ' . $date->format('Y');
    }

    /** "1er" for the first of the month, a plain number otherwise. */
    private static function dayNumber(\DateTimeImmutable $date): string
    {
        $day = (int) $date->format('j');

        return $day === 1 ? '1er' : (string) $day;
    }

    private static function month(\DateTimeImmutable $date): string
    {
        $months = [
            1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];

        return $months[(int) $date->format('n')];
    }
}
