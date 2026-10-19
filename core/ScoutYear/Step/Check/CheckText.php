<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\Member\SectionYearHeadcount;
use Core\Service\DateInput;

/**
 * The few wordings and lookups the « Année scoute » step checks share, so
 * that « 1 animé » / « 3 animés », a date and a section's name read the
 * same on every step.
 */
final class CheckText
{
    private function __construct()
    {
    }

    /** « 0 foyer à corriger », « 1 foyer à corriger », « 3 foyers à corriger »: French keeps 0 singular. */
    public static function count(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count <= 1 ? $singular : $plural);
    }

    /** « 15/08/2027 » from a 'Y-m-d' date; null stays null, anything else is shown as stored. */
    public static function date(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return DateInput::iso($date)?->format('d/m/Y') ?? $date;
    }

    /**
     * The member sheet of a member_year — the id /admin/members/{id} takes
     * (it moves onto the person's latest year itself).
     */
    public static function memberUrl(int $memberYearId): string
    {
        return '/admin/members/' . $memberYearId;
    }

    /**
     * Section id => its name (its Desk code when it has none), for sections
     * that may since have gone inactive or hidden — a section a member left
     * last year is still named.
     *
     * @param array<int, int|null> $sectionIds
     * @return array<int, string>
     */
    public static function sectionLabels(SectionService $sections, array $sectionIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(?int $id): int => (int) $id, $sectionIds),
            static fn(int $id): bool => $id > 0
        )));

        $labels = [];
        foreach ($sections->findByIds($ids) as $id => $section) {
            $name = trim((string) ($section['name'] ?? ''));
            $labels[$id] = $name !== '' ? $name : $section['desk_code'];
        }

        return $labels;
    }

    /**
     * The sections a check looks at in the target year (chantier « Année
     * scoute » §2.2): visible, with active members that year. The Staff
     * d'U is kept only when $withUnitStaff — it has no responsable to
     * designate, but it does have photos to take.
     *
     * @return list<SectionYearHeadcount>
     */
    public static function checkedSections(SectionService $sections, int $scoutYearId, bool $withUnitStaff): array
    {
        return array_values(array_filter(
            $sections->getSectionHeadcountsForYear($scoutYearId),
            static fn(SectionYearHeadcount $section): bool => $withUnitStaff || !$section->isUnitStaff
        ));
    }
}
