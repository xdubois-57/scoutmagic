<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Service\TextNormalizerService;

/**
 * Does this Desk formation wording mean "breveté"?
 *
 * The federation grants a reduction for a qualified animateur, and the
 * only thing the site holds about it is `member_years.formation_level` —
 * the federation's own wording, verbatim, which differs between exports.
 * A folded "brevet" is what recognises the usual ones.
 *
 * **A wording this does not recognise is not "no brevet", it is "the site
 * cannot say"**, and the verification report shows the line as
 * undetermined rather than claiming a reduction is missing. Reporting an
 * unrecognised wording as a missing reduction would put a false alarm on
 * every unit whose export words it differently.
 *
 * The `leadership` module already lets a chef d'unité map its own leftover
 * wordings onto a normalised step (`leadership_formation_levels`,
 * ARCHITECTURE.md §8.65). Consuming that through an `Api` interface is the
 * refinement this would deserve if the heuristic turns out not to be
 * enough — it is deliberately not a hard dependency, and this module works
 * with `leadership` switched off.
 */
final class BrevetDetector
{
    public static function isBrevet(?string $formationLevel): bool
    {
        if ($formationLevel === null || trim($formationLevel) === '') {
            return false;
        }

        return str_contains(TextNormalizerService::fold($formationLevel), 'brevet');
    }
}
