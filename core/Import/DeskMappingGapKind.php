<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * The three kinds of Desk value this application has to recognise, and the
 * three places in the code that decide whether it does (issue #356).
 *
 * Each case names the place in the source that decides, so that whoever
 * reads a report knows what to edit without reopening the question. For a
 * branch and for a CSV header that place is a hard-coded table, which is
 * what makes an unresolved value a defect with a fix rather than a unit
 * doing something wrong: the federation invents a branch or renames a
 * column, and this code learns about it one version later.
 *
 * There is deliberately no case for a SECTION. A section's name is chosen
 * by the unit, there is no central table it could fail to match, and it
 * identifies the unit far better than a federal label does — so it is not
 * a mapping that can be missing, and it never travels.
 *
 * **And none for a fee category, which this chantier started out with.**
 * A Desk tariff outside the three household ones is an ordinary, expected
 * state rather than a defect: ARCHITECTURE.md §8.74 says reporting one
 * « would be a false positive on every unit », and
 * `Modules\Fees\Service\FeeCategoryClassifierTest` pins « Cotisation
 * invités », « Cotisation de solidarité » and « COT_iAM_LOCAL » as
 * wordings that must answer null for ever. The site cannot tell such a
 * tariff from one of the three spelled unusually — which is exactly why
 * `classify()` refuses to guess — so it cannot report either as
 * unresolved without being wrong about the other.
 */
enum DeskMappingGapKind: string
{
    case FUNCTION = 'function';
    case BRANCH = 'branch';
    case CSV_HEADER = 'csv_header';

    /**
     * The journal event type this kind writes at import time
     * ({@see MappingResolver}, {@see DeskCsvParser}).
     */
    public function journalType(): string
    {
        return match ($this) {
            self::FUNCTION => 'desk_function_unknown',
            self::BRANCH => 'desk_branch_not_canonical',
            self::CSV_HEADER => 'desk_csv_header_unexpected',
        };
    }

    /**
     * Where the decision is made, as a reader of a support package or of
     * the central page would go looking for it.
     *
     * A FUNCTION is the odd one out: there is no list of known functions
     * anywhere in this code, so no release can recognise one and this
     * names the method that creates them rather than a table to complete.
     */
    public function codeTable(): string
    {
        return match ($this) {
            self::FUNCTION => 'Core\Import\MappingResolver::resolveFunction()',
            self::BRANCH => 'Core\Import\AgeBranchRepository::canonicalSortOrder()',
            self::CSV_HEADER => 'Core\Import\DeskCsvParser::EXPECTED_HEADERS',
        };
    }
}
