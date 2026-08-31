<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Api;

/**
 * Reading a Desk formation wording the way this unit has decided it should
 * be read (ARCHITECTURE.md §7.5, module → module).
 *
 * Desk exports the federation's own wording, which differs between exports
 * and between years, and the leadership module is where a unit maps its
 * own leftovers onto a normalised step (`leadership_formation_levels`,
 * §8.65). Two things then follow from one string — whether the ONE counts
 * the person as qualified, and whether the federation's fee reduction
 * applies — and before this interface existed, `fees` answered the second
 * one with a heuristic of its own that did not know about the BACV.
 *
 * **A consumer must work without it.** The interface is offered as a
 * nullable dependency and the module can be switched off; the fallback is
 * the consumer's own reading of the string, never an exception and never a
 * silently different answer that nobody attributes to a disabled module.
 *
 * **Only a formation level goes through here.** The wording of an invoice
 * line is not one, however much it looks like one: routing a line
 * descriptor into a unit's level mapping would let a vocabulary decision
 * about people change how a document is read.
 */
interface FormationLevelInterface
{
    /**
     * Resolve one raw `member_years.formation_level` value.
     *
     * Never returns null: an empty value is "nothing encoded" and an
     * unreadable one is `recognised: false`. Both are answers.
     */
    public function resolve(?string $rawFormationLevel): FormationLevel;
}
