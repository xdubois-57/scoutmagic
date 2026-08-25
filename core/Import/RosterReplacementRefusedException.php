<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * A Desk import refused by {@see RosterReplacementGuard}, carrying the
 * counted assessment the screen states its consequences from.
 *
 * It extends {@see ImportException} — it is an import that did not
 * happen, and nothing was written — so any caller that only knows about
 * import failures still behaves correctly. `ImportController` catches
 * this one first, to render the barrier screen instead of a one-line
 * flash: a message that says "216 membres seraient désactivés · seule la
 * section Baladins 1 figure dans ce fichier" does not fit in a flash, and
 * a generic one would be the very thing this guard exists to prevent.
 *
 * The message stays French and names no one, like every
 * {@see \Core\Exception\UserFacingException} in this codebase.
 */
class RosterReplacementRefusedException extends ImportException
{
    public function __construct(public readonly RosterReplacementAssessment $assessment)
    {
        parent::__construct(
            $assessment->verdict === RosterReplacementVerdict::NO_ADMIN_LEFT
                ? "Cet import laisserait le site sans administrateur. Il est refusé."
                : "Ce fichier ne correspond pas à l'unité telle que le site la connaît. Aucune modification n'a été faite."
        );
    }
}
