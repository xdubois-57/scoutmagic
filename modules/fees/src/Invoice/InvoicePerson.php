<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * One name listed under an invoice line.
 *
 * **The key is name + first name + birth date, never name + birth date.**
 * Twins exist, they are registered together, and they appear on the same
 * invoice — a key without the first name would merge two children into
 * one and make the count wrong on the one line where it matters most.
 *
 * Case and accents are inconsistent inside the document itself
 * (`PISSOORT` on one line, `Pissoort` on another, `dubois basile` in
 * lower case), so the key folds — see {@see PersonMatchKey}, which the
 * roster side builds the very same key with.
 */
final class InvoicePerson
{
    public function __construct(
        public readonly string $lastName,
        public readonly string $firstName,
        /** ISO `Y-m-d`, so it compares against member_years without reformatting. */
        public readonly string $birthDate,
        public readonly ?string $functionLabel
    ) {
    }

    public function matchKey(): string
    {
        return PersonMatchKey::for($this->lastName, $this->firstName, $this->birthDate);
    }
}
