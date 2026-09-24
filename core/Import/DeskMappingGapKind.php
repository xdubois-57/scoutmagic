<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * The four kinds of Desk value this application has to recognise, and the
 * four places in the code that decide whether it does (issue #356).
 *
 * Each case is backed by a hard-coded table somewhere in the source, which
 * is what makes an unresolved value a defect with a fix rather than a unit
 * doing something wrong: the federation invents a function, a branch or a
 * tariff, and this code learns about it one version later. `codeTable()`
 * names the table so that whoever reads a report knows what to edit
 * without reopening the question.
 *
 * There is deliberately no case for a SECTION. A section's name is chosen
 * by the unit, there is no central table it could fail to match, and it
 * identifies the unit far better than a federal label does — so it is not
 * a mapping that can be missing, and it never travels.
 */
enum DeskMappingGapKind: string
{
    case FUNCTION = 'function';
    case BRANCH = 'branch';
    case FEE_CATEGORY = 'fee_category';
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
            self::FEE_CATEGORY => 'desk_fee_without_scale',
            self::CSV_HEADER => 'desk_csv_header_unexpected',
        };
    }

    /**
     * The hard-coded table to complete in a later version, as a reader of
     * a support package or of the central page would go looking for it.
     *
     * `FeeCategoryClassifier::NEEDLES` and not the federal scale's own
     * `FIELD_BY_CATEGORY`: the latter translates this site's three
     * household categories into the wording used on the federation's
     * cotisations page, and a Desk tariff code never meets it.
     */
    public function codeTable(): string
    {
        return match ($this) {
            self::FUNCTION => 'Core\Import\MappingResolver::resolveFunction()',
            self::BRANCH => 'Core\Import\AgeBranchRepository::canonicalSortOrder()',
            self::FEE_CATEGORY => 'Modules\Fees\Service\FeeCategoryClassifier::NEEDLES',
            self::CSV_HEADER => 'Core\Import\DeskCsvParser::EXPECTED_HEADERS',
        };
    }
}
