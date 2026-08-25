<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * What {@see RosterReplacementGuard} concluded about a CSV, and — the
 * part that matters — whether a human may still insist.
 *
 * Three of the four verdicts describe a file that is probably a mistake;
 * the fourth describes a file that would lock everyone out of the site
 * they would then need in order to repair it. Only the fourth removes the
 * confirmation instead of making it harder.
 */
enum RosterReplacementVerdict: string
{
    /** Nothing unusual: the import proceeds without asking anything. */
    case ALLOWED = 'allowed';

    /**
     * The file names a single section while the year's roster holds
     * several — the signature of an export filtered on one section
     * instead of the whole unit. No threshold, and almost no false
     * positive: a unit really down to one section has no other section
     * in its roster either.
     */
    case FILTERED_EXPORT = 'filtered_export';

    /**
     * All the sections are there, but the file drops an unusual share of
     * the year's members — a truncated export, or a genuine mass
     * departure the chief should confirm they meant.
     */
    case MASS_DEACTIVATION = 'mass_deactivation';

    /**
     * The import would leave the site without a single administrative
     * access. Refused with the confirmation word correctly typed, because
     * the page that would undo it is behind the access it removes.
     */
    case NO_ADMIN_LEFT = 'no_admin_left';

    /** Whether typing the confirmation word may still carry the import through. */
    public function allowsOverride(): bool
    {
        return match ($this) {
            self::FILTERED_EXPORT, self::MASS_DEACTIVATION => true,
            self::ALLOWED, self::NO_ADMIN_LEFT => false,
        };
    }
}
