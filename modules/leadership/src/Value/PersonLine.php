<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Value;

/**
 * One person on one of this module's lists, reduced to what a chief d'unité
 * needs in order to go and talk to them: who they are, where they are, and
 * the one fact that put them on this list.
 *
 * `note` is always a sentence the calling service wrote out in full, never
 * a code the template has to interpret and never a status. That keeps every
 * carefully-worded rule of this module — "CQA à signer" versus "CQA ou
 * extrait — à vérifier dans Desk", or the fact that a steward's date is a
 * first-appearance date rather than a Desk registration — in the service
 * that reasoned about it, next to the reasoning, rather than scattered
 * across four Twig files where the next person to touch one of them would
 * have to rediscover why the wording is what it is.
 */
final class PersonLine
{
    /**
     * @param string|null $detail  Secondary line: function, section, branch-year…
     * @param string|null $note    Fully-written French sentence explaining the entry.
     * @param string      $severity One of 'normal', 'warning', 'critical' — a
     *                              display hint for the stewards countdown only.
     *                              Never a verdict on a person's paperwork.
     */
    public function __construct(
        public readonly int $memberYearId,
        /**
         * The person's totem, when they have one, and null otherwise —
         * NOT the site's usual `totem ?? first_name` display name.
         *
         * These lists lead with the full legal name, because their whole
         * purpose is going and talking to somebody or looking them up in
         * Desk, and Desk knows nobody by their totem. The totem is then a
         * second line worth adding when it exists — but falling back to
         * the first name there would print "Arthur" under "Arthur Genin",
         * a line that repeats what is above it and reads as a rendering
         * defect.
         */
        public readonly ?string $totem,
        public readonly string $fullName,
        public readonly ?string $sectionName = null,
        public readonly ?string $detail = null,
        public readonly ?string $note = null,
        public readonly string $severity = 'normal',
        /** Days remaining/elapsed, when the line carries a count. */
        public readonly ?int $days = null,
    ) {
    }
}
