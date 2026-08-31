<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

class MemberFunctionInfo
{
    public function __construct(
        public readonly string $functionLabel,
        public readonly string $functionRole,
        public readonly ?string $branchName,
        public readonly ?string $sectionName,
        public readonly ?string $sectionCode,
        public readonly bool $isMainFunction,
        public readonly ?string $startDate,
        public readonly ?string $endDate
    ) {
    }

    /**
     * Two entries identical in every field are one function said twice,
     * never two functions.
     *
     * A Desk export carries ONE ROW PER (function × address), so a member
     * with two addresses and one function produced two strictly identical
     * `member_functions` rows. Core\Import\DeskImportService collapses
     * them at the source now, but only for years imported since — so the
     * years already in the database are collapsed here instead, on the
     * way out, and read correctly without waiting for the next import.
     *
     * Two entries differing on ANY field stay two entries: « Animateur /
     * Louveteaux » and « Animateur / Baladins » are two real functions,
     * and merging them would lose information rather than restore it.
     * First occurrence wins, so a main function keeps its place at the
     * head of the list — MemberProfile::getMainFunction() falls back to
     * the first entry when nothing is flagged.
     *
     * It lives on the value object because both hydration paths build
     * exactly this list (Core\Member\MemberService for one member,
     * Core\Member\SectionService for a whole roster) and two copies of
     * the rule would eventually answer differently.
     *
     * @param array<int, self> $functions
     * @return array<int, self>
     */
    public static function deduplicate(array $functions): array
    {
        $unique = [];
        $seen = [];

        foreach ($functions as $function) {
            $key = serialize([
                $function->functionLabel,
                $function->functionRole,
                $function->branchName,
                $function->sectionName,
                $function->sectionCode,
                $function->isMainFunction,
                $function->startDate,
                $function->endDate,
            ]);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $unique[] = $function;
        }

        return $unique;
    }
}
