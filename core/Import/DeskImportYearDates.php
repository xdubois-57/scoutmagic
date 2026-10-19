<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\Config\SettingService;

/**
 * The two days of the year that decide which scout years a Desk import may
 * target ({@see DeskImportYearPolicy}): when the next year opens to the
 * import, and when the public year closes to it.
 *
 * Both are MM-JJ, with no year, because the same configuration repeats
 * from one season to the next; the policy places them in the calendar year
 * the public scout year ends in. They are read and checked here, together,
 * because neither means anything without the other: an opening on or
 * after the closing would describe a window that never exists.
 *
 * **A stored pair that does not hold together falls back to the defaults,
 * BOTH of them.** The settings page refuses an incoherent pair
 * ({@see self::coherenceError()}, called by SettingsController), so this
 * only happens to a value written some other way — an import of the
 * settings table, a hand edit. Keeping one of the two would be guessing
 * which one the person meant; the defaults are a pair known to work.
 */
final class DeskImportYearDates
{
    /** Day and month from which the year after the public one may be imported, and becomes the one proposed. */
    public const SETTING_NEXT_START = 'scout_year_import_next_start_date';

    /**
     * Day and month from which only the next year may be imported. The
     * same setting labels the phases of the « Année scoute » page
     * ({@see \Core\ScoutYear\ScoutYearTransitionService::SETTING_DESK_ENCODING_DATE}).
     */
    public const SETTING_ENCODING = 'scout_year_desk_encoding_date';

    /**
     * The two settings' labels on Configuration > Réglages, kept here
     * beside the sentences that name them so the two cannot drift apart
     * (the composition root registers the settings with them).
     */
    public const LABEL_NEXT_START = "Ouverture de l'import pour l'année suivante";
    public const LABEL_ENCODING = "Date d'encodage Desk";

    public const DEFAULT_NEXT_START = '07-01';
    public const DEFAULT_ENCODING = '08-15';

    /**
     * Both days must fall before this one: they belong to the summer that
     * closes the public scout year, and a day from 1 September onwards is
     * already in the next one.
     */
    private const SEASON_END_EXCLUSIVE = '09-01';

    private const PATTERN = '/^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/';

    private function __construct(
        public readonly string $nextStart,
        public readonly string $encoding,
        /** True when the stored pair was refused and the defaults stand in for it. */
        public readonly bool $fellBack
    ) {
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_NEXT_START, self::DEFAULT_ENCODING, false);
    }

    public static function fromSettings(SettingService $settings): self
    {
        $nextStart = trim((string) $settings->get(self::SETTING_NEXT_START, null, self::DEFAULT_NEXT_START));
        $encoding = trim((string) $settings->get(self::SETTING_ENCODING, null, self::DEFAULT_ENCODING));

        return self::fromValues($nextStart, $encoding);
    }

    public static function fromValues(string $nextStart, string $encoding): self
    {
        if (self::coherenceError($nextStart, $encoding) !== null) {
            return new self(self::DEFAULT_NEXT_START, self::DEFAULT_ENCODING, true);
        }

        return new self($nextStart, $encoding, false);
    }

    /**
     * Why this pair cannot be saved, as the sentence the settings page
     * shows — or null when it holds together. Every sentence names both
     * values, because the one just typed is only half of the problem and
     * the other one is on a different row of the page.
     */
    public static function coherenceError(string $nextStart, string $encoding): ?string
    {
        if (!self::isDayOfYear($nextStart) || !self::isDayOfYear($encoding)) {
            return "Indiquez chaque date au format MM-JJ, par exemple 07-01 (ouverture de l'import pour l'année "
                . "suivante : « {$nextStart} », date d'encodage Desk : « {$encoding} »).";
        }

        if (strcmp($nextStart, self::SEASON_END_EXCLUSIVE) >= 0 || strcmp($encoding, self::SEASON_END_EXCLUSIVE) >= 0) {
            return "Les deux dates doivent tomber avant le 1er septembre, dans l'année scoute qui se termine "
                . "(ouverture de l'import pour l'année suivante : {$nextStart}, date d'encodage Desk : {$encoding}).";
        }

        if (strcmp($nextStart, $encoding) >= 0) {
            return "L'ouverture de l'import pour l'année suivante ({$nextStart}) doit précéder la date d'encodage "
                . "Desk ({$encoding}).";
        }

        return null;
    }

    /**
     * MM-JJ naming a day that exists in some year — 02-29 included, since
     * it exists every fourth one, and 04-31 refused, since it never does.
     */
    private static function isDayOfYear(string $value): bool
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        [$month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, 2024);
    }
}
