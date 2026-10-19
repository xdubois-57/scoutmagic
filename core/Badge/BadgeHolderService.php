<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;

/**
 * Who wore which badge in one scout year — the Badges holders pages of
 * the Espace chefs d'U (issue #621, docs/chantiers/CHANTIER-badges.md).
 *
 * Read only: assigning a badge stays on /chefs/staffs, where each member
 * carries their own switches. Two places toggling the same thing would
 * end up disagreeing.
 *
 * A constant number of queries whatever the size of the unit: the year's
 * holdings in one, the badges in one, and the people through
 * SectionService::hydrateMemberProfiles() — four queries for the whole
 * set, never one per person.
 */
class BadgeHolderService
{
    public function __construct(
        private BadgeRepository $badges,
        private MemberBadgeRepository $memberBadges,
        private SectionService $sections
    ) {
    }

    /**
     * The badges worn in $scoutYearId, each with its holders.
     *
     * - Only badges with at least one holder.
     * - A deactivated badge still worn is listed — hiding it would hide
     *   its holder from every screen, with the badge still to take off.
     * - A stable order: the default badges, then the automatic ones in the
     *   order of their sections, then the others alphabetically. Sorting
     *   by holder count would make the page reshuffle from one week to the
     *   next.
     * - Within a badge, holders by name.
     *
     * @return list<BadgeHolders>
     */
    public function holdersForYear(int $scoutYearId): array
    {
        $holdings = $this->memberBadges->findHoldingsForScoutYear($scoutYearId);
        if ($holdings === []) {
            return [];
        }

        $profiles = $this->sections->hydrateMemberProfiles(array_column($holdings, 'member_year_id'));

        /** @var array<int, list<BadgeHolder>> $holdersByBadge */
        $holdersByBadge = [];
        foreach ($holdings as $holding) {
            $profile = $profiles[$holding['member_year_id']] ?? null;
            if ($profile === null) {
                continue;
            }
            $holdersByBadge[$holding['badge_id']][] = self::holderFrom($holding['member_year_id'], $profile);
        }

        $collator = class_exists(\Collator::class) ? new \Collator('fr_BE') : null;
        $compare = static fn(string $a, string $b): int => $collator !== null
            ? (int) $collator->compare($a, $b)
            : strcasecmp($a, $b);

        $groups = [];
        foreach ($this->badges->findAll() as $badge) {
            $holders = $holdersByBadge[$badge->id] ?? [];
            if ($holders === []) {
                continue;
            }
            usort($holders, static fn(BadgeHolder $a, BadgeHolder $b): int => $compare($a->name, $b->name));
            $groups[] = new BadgeHolders($badge, $holders);
        }

        $sectionRank = $this->sectionRank();
        usort(
            $groups,
            static fn(BadgeHolders $a, BadgeHolders $b): int => self::compareBadges(
                $a->badge,
                $b->badge,
                $sectionRank,
                $compare
            )
        );

        return $groups;
    }

    /**
     * The badges worn in $scoutYearId filed by section, for the sections
     * $sectionIds a caller is checking — BadgeYearAggregate says where each
     * holding goes and why a referent badge never makes its own section
     * look incomplete.
     *
     * Same holdings and same people as holdersForYear() (a deactivated
     * badge still worn is filed too), the same order of badges inside a
     * section and of holders inside a badge, and the same constant number
     * of queries.
     *
     * @param array<int, int> $sectionIds
     */
    public function sectionAggregateForYear(int $scoutYearId, array $sectionIds): BadgeYearAggregate
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        $unitStaffSectionId = $this->sections->findByDeskCode(UnitStaffSectionService::DESK_CODE)['id'] ?? null;

        $holdings = $this->memberBadges->findHoldingsWithSectionForScoutYear($scoutYearId);
        $profiles = $holdings !== []
            ? $this->sections->hydrateMemberProfiles(array_column($holdings, 'member_year_id'))
            : [];

        $badges = [];
        foreach ($this->badges->findAll() as $badge) {
            $badges[$badge->id] = $badge;
        }

        /** @var array<string, array<int, list<BadgeHolder>>> $filed section key => badge id => holders */
        $filed = [];
        foreach ($holdings as $holding) {
            $badge = $badges[$holding['badge_id']] ?? null;
            $profile = $profiles[$holding['member_year_id']] ?? null;
            if ($badge === null || $profile === null) {
                continue;
            }
            $sectionId = $badge->referentSectionId !== null ? $unitStaffSectionId : $holding['section_id'];
            $filed[$sectionId === null ? 'none' : (string) $sectionId][$badge->id][] = self::holderFrom(
                $holding['member_year_id'],
                $profile
            );
        }

        $collator = class_exists(\Collator::class) ? new \Collator('fr_BE') : null;
        $compare = static fn(string $a, string $b): int => $collator !== null
            ? (int) $collator->compare($a, $b)
            : strcasecmp($a, $b);
        $sectionRank = $this->sectionRank();
        $groupsOf = static function (array $holdersByBadge) use ($badges, $compare, $sectionRank): array {
            $groups = [];
            foreach ($holdersByBadge as $badgeId => $holders) {
                usort($holders, static fn(BadgeHolder $a, BadgeHolder $b): int => $compare($a->name, $b->name));
                $groups[] = new BadgeHolders($badges[$badgeId], $holders);
            }
            usort($groups, static fn(BadgeHolders $a, BadgeHolders $b): int => self::compareBadges(
                $a->badge,
                $b->badge,
                $sectionRank,
                $compare
            ));

            return $groups;
        };

        $listed = $unitStaffSectionId !== null && !in_array($unitStaffSectionId, $sectionIds, true)
            ? [...$sectionIds, $unitStaffSectionId]
            : $sectionIds;
        $bySection = [];
        foreach ($listed as $sectionId) {
            $bySection[$sectionId] = $groupsOf($filed[(string) $sectionId] ?? []);
        }

        $wornInASection = [];
        foreach ($filed as $key => $holdersByBadge) {
            if ($key === 'none') {
                continue;
            }
            foreach (array_keys($holdersByBadge) as $badgeId) {
                $wornInASection[$badgeId] = true;
            }
        }
        $defaultBadgesUnworn = array_values(array_filter(
            $badges,
            static fn(Badge $badge): bool => $badge->isDefault && $badge->isActive
                && $badge->referentSectionId === null && !isset($wornInASection[$badge->id])
        ));
        usort($defaultBadgesUnworn, static fn(Badge $a, Badge $b): int => $compare($a->name, $b->name));

        $transversalGaps = [];
        foreach ($badges as $badge) {
            if ($badge->referentSectionId !== null) {
                continue;
            }
            $with = [];
            $without = [];
            foreach ($sectionIds as $sectionId) {
                if (isset($filed[(string) $sectionId][$badge->id])) {
                    $with[] = $sectionId;
                } else {
                    $without[] = $sectionId;
                }
            }
            if ($with !== [] && $without !== []) {
                $transversalGaps[] = new TransversalBadgeGap($badge, $with, $without);
            }
        }
        usort(
            $transversalGaps,
            static fn(TransversalBadgeGap $a, TransversalBadgeGap $b): int => self::compareBadges(
                $a->badge,
                $b->badge,
                $sectionRank,
                $compare
            )
        );

        return new BadgeYearAggregate(
            $bySection,
            $groupsOf($filed['none'] ?? []),
            $defaultBadgesUnworn,
            $transversalGaps
        );
    }

    /**
     * holdersForYear()'s order of badges: kind, then the referent badges in
     * the order of their sections, then by name.
     *
     * @param array<int, int> $sectionRank
     * @param \Closure(string, string): int $compare
     */
    private static function compareBadges(Badge $a, Badge $b, array $sectionRank, \Closure $compare): int
    {
        $byKind = self::kindRank($a) <=> self::kindRank($b);
        if ($byKind !== 0) {
            return $byKind;
        }
        if ($a->referentSectionId !== null && $b->referentSectionId !== null) {
            $bySection = ($sectionRank[$a->referentSectionId] ?? PHP_INT_MAX)
                <=> ($sectionRank[$b->referentSectionId] ?? PHP_INT_MAX);
            if ($bySection !== 0) {
                return $bySection;
            }
        }

        return $compare($a->name, $b->name);
    }

    private static function holderFrom(int $memberYearId, MemberProfile $profile): BadgeHolder
    {
        $main = $profile->getMainFunction();

        return new BadgeHolder(
            $memberYearId,
            $profile->getDisplayNameFull(),
            $main?->sectionName,
            $main?->functionLabel
        );
    }

    /**
     * Default badges first, then the automatic ones, then the others. A
     * referent badge is seeded with is_default set too — its section is
     * what tells it apart.
     */
    private static function kindRank(Badge $badge): int
    {
        if ($badge->referentSectionId !== null) {
            return 1;
        }

        return $badge->isDefault ? 0 : 2;
    }

    /**
     * Each section's place in the unit's own order (branch, then Desk
     * code), hidden ones included: a section hidden since still orders the
     * referent badge somebody wore for it. A section no longer active at
     * all has no place, and its referent badge sorts after the others of
     * its kind, by name.
     *
     * @return array<int, int>
     */
    private function sectionRank(): array
    {
        $rank = [];
        foreach ($this->sections->getAllWithBranches(includeHidden: true) as $position => $section) {
            $rank[(int) $section['id']] = (int) $position;
        }

        return $rank;
    }
}
