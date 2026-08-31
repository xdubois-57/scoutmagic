<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * One function held by one person in one scout year.
 *
 * A person can hold several, and functions are NOT deduplicated by
 * DeskCsvParser::buildMember() the way addresses are — every row carrying a
 * non-empty FONCTION becomes a ParsedFunction. That asymmetry is why a member
 * with two addresses and one function still produces two rows with the SAME
 * function, and why the writer multiplies functions by addresses rather than
 * emitting one row per function.
 *
 * Core\Import\DeskImportService collapses the repeat before it reaches
 * `member_functions`, so two identical parsed functions are stored once. That
 * happens downstream of everything this fixture describes and changes nothing
 * about what belongs in the CSV.
 *
 * `section` and `branch` are null for a unit-level function (Chef d'unité,
 * Intendant d'unité, Trésorier d'unité): those rows carry no Branche, no
 * Section and no Date début in a real export either.
 */
final class FunctionAssignment
{
    public function __construct(
        public readonly string $functionCode,
        public readonly ?string $branch,
        public readonly ?string $section,
        /**
         * The "SECTION" (all-caps) column. Never read by the parser — see
         * DeskCsvParser::buildMember()'s own comment — and deliberately
         * inconsistent with `section` here so that any code which started
         * reading it would produce visibly wrong sections rather than
         * plausible ones.
         */
        public readonly ?string $ignoredSectionCode,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly ?string $mandateEnd,
        public readonly bool $isMain,
    ) {
    }
}
