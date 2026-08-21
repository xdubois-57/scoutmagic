<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Ai;

/**
 * Something a model proposed, which a human has not yet accepted (§6.36).
 *
 * **A suggestion is not a value.** It is deliberately its own type rather
 * than the plain string or integer it wraps, because the whole rule this
 * module has to hold — *the AI never changes a business fact on its own* —
 * survives only if a suggested reading cannot be passed to a setter by
 * accident. To use one, a caller has to reach through `->value`, which is
 * a visible act at a call site somebody reviews.
 *
 * `$rationale` is what the model said about its own answer, shown next to
 * it so a manager accepting a suggestion knows what they are accepting
 * rather than trusting a number that appeared.
 */
class Suggestion
{
    public function __construct(
        public readonly string $value,
        public readonly ?string $rationale = null,
        /**
         * The model's own confidence when it offered one, 0–100. Displayed,
         * never acted on: nothing in this module behaves differently at 90
         * than at 40, because a confident wrong answer and a hesitant wrong
         * answer are equally wrong and a threshold would only decide which
         * ones a human never sees.
         */
        public readonly ?int $confidence = null
    ) {
    }

    public function asInt(): ?int
    {
        $normalised = str_replace([' ', ','], ['', '.'], trim($this->value));

        return is_numeric($normalised) ? (int) round((float) $normalised) : null;
    }

    /**
     * The reading in thousandths, for a meter (§6.22).
     *
     * The same integer-in-thousandths rule the rest of the module uses —
     * a meter index is not money, but it has money's problem, and a float
     * differenced against another float is how a bill ends up a cent off in
     * a way nobody can reproduce. Accepts the French decimal comma, since a
     * model reading a French dial will write one.
     */
    public function asThousandths(): ?int
    {
        $normalised = str_replace([' ', ','], ['', '.'], trim($this->value));
        if (!is_numeric($normalised)) {
            return null;
        }

        return (int) round((float) $normalised * 1000);
    }
}
