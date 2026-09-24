<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Service\DateInput;

/**
 * How this module writes a day and an hour: « samedi 7 novembre », « 8 h
 * 30 » — the way the maquette and a family say them. Built here, in PHP,
 * so no template ever reads a date itself (Core\View\DateFilterExtension).
 */
final class CarpoolFormat
{
    private const DAYS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    private const MONTHS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * « samedi 7 novembre », with the year only when it is not the current
     * one — an outing is almost always this year, and a year on every line
     * is noise until the day it is not.
     */
    public static function day(string $ymd, ?\DateTimeImmutable $today = null): string
    {
        $date = DateInput::fromStorage($ymd);
        if ($date === null) {
            return '';
        }
        $today ??= new \DateTimeImmutable('today');

        $text = self::DAYS[(int) $date->format('w')] . ' ' . (int) $date->format('j') . ' '
            . self::MONTHS[(int) $date->format('n')];

        return $date->format('Y') === $today->format('Y') ? $text : $text . ' ' . $date->format('Y');
    }

    /** « 8 h 30 », « 9 h 00 ». */
    public static function time(string $hhmm): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m) !== 1) {
            return $hhmm;
        }

        return (int) $m[1] . ' h ' . $m[2];
    }

    /** « 2 places libres », « 1 place libre », « complet ». */
    public static function freeSeats(int $free): string
    {
        if ($free <= 0) {
            return 'complet';
        }

        return $free === 1 ? '1 place libre' : $free . ' places libres';
    }
}
