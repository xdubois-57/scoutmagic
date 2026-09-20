<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact;

/**
 * One scout year of a member's history, as the contact card's NOTE states
 * it: a year label and the functions held that year.
 *
 * A year carries SEVERAL functions (a chief of two sections, a chief who
 * is also the treasurer), and they belong on one line — a reader scanning
 * ten years of history reads years, not rows.
 *
 * The section is NAMED, never its Desk code: a section renamed in
 * Configuration reads under its new name for every year it appears in,
 * because the name is resolved from the section row rather than copied
 * into the history. A function attached to no section at all (Trésorier,
 * Infirmier) stops at the function, with no orphan separator behind it.
 */
final class ContactAffiliation
{
    /** Between the year and a function, and between a function and its section. */
    private const FIELD_SEPARATOR = ' · ';

    /** Between two functions of the same year. */
    private const FUNCTION_SEPARATOR = ' ; ';

    /**
     * @param list<array{function: string, section: ?string}> $functions
     */
    public function __construct(
        public readonly string $scoutYearLabel,
        public readonly array $functions
    ) {
    }

    /**
     * « 2025-2026 · Animateur · Louveteaux ; Trésorier »
     */
    public function format(): string
    {
        $rendered = [];
        foreach ($this->functions as $function) {
            $section = $function['section'];
            $rendered[] = $section !== null && $section !== ''
                ? $function['function'] . self::FIELD_SEPARATOR . $section
                : $function['function'];
        }

        if ($rendered === []) {
            return $this->scoutYearLabel;
        }

        return $this->scoutYearLabel . self::FIELD_SEPARATOR . implode(self::FUNCTION_SEPARATOR, $rendered);
    }
}
