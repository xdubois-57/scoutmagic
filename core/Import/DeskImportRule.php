<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * The rules a Desk export must satisfy before a single line of it is
 * written (issue #854). The value is the rule's number in that issue, so a
 * journal entry, a test and the issue can be read side by side.
 *
 * Rule 2 is missing on purpose: it is not a refusal but what the import
 * does with a date rule 1 accepted — store it as `Y-m-d`.
 */
enum DeskImportRule: int
{
    case BIRTH_DATE = 1;
    case FIELD_COUNT = 3;
    case TIERS = 4;
    case CONSISTENT_IDENTITY = 5;
    case NAME = 6;
    case GENDER = 7;
    case BOOLEAN = 8;
    case FUNCTION_CODE = 9;
    case EMAIL = 10;
    case FUNCTION_DATE = 11;
    case SECTION_WITHOUT_BRANCH = 12;
    case FUNCTION_AFTER_YEAR = 13;
    case MAIN_FUNCTION = 14;

    /**
     * The key a refusal is counted under in the journal. A count per rule
     * is all the journal ever holds of a refused file — never a value,
     * never a Tiers (SECURITY.md §13).
     */
    public function journalKey(): string
    {
        return match ($this) {
            self::BIRTH_DATE => 'birth_date',
            self::FIELD_COUNT => 'field_count',
            self::TIERS => 'tiers',
            self::CONSISTENT_IDENTITY => 'consistent_identity',
            self::NAME => 'name',
            self::GENDER => 'gender',
            self::BOOLEAN => 'boolean',
            self::FUNCTION_CODE => 'function_code',
            self::EMAIL => 'email',
            self::FUNCTION_DATE => 'function_date',
            self::SECTION_WITHOUT_BRANCH => 'section_without_branch',
            self::FUNCTION_AFTER_YEAR => 'function_after_year',
            self::MAIN_FUNCTION => 'main_function',
        };
    }
}
