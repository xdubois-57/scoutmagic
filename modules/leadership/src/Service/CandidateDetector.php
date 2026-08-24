<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

/**
 * Decides whether a Desk function label marks its holder as a candidate.
 *
 * Desk prefixes the function with the word "candidat" for as long as the
 * holder's obligations are not — or are no longer — in order, and takes the
 * prefix off again by itself once they are. Two consequences shape
 * everything this module does with the answer:
 *
 * - **The list is complete and maintains itself.** The status comes back on
 *   its own when a CQA or an extrait expires, for an animateur in post for
 *   fifteen years exactly as for somebody who arrived in September. This is
 *   therefore not a list of new arrivals, and the pages must never present
 *   it as one.
 * - **There is no expiry blind spot to compensate for.** Nothing here tries
 *   to guess when a document lapses, because Desk has already applied that
 *   judgement by the time we read the label.
 *
 * Substring matching, folded for case and accents, is deliberate: the word
 * is federation-wide, but the rest of the label is not ("Candidat
 * animateur", "candidat·e animatrice", "CANDIDAT CHEF"), and an exact-match
 * list of labels would need editing every time a unit typed one
 * differently.
 */
final class CandidateDetector
{
    /**
     * The federation's own word. Folding means this also catches
     * "candidate", "candidat·e" and "candidature".
     */
    private const NEEDLE = 'candidat';

    public function isCandidateLabel(?string $functionLabel): bool
    {
        return TextMatcher::contains($functionLabel, self::NEEDLE);
    }
}
