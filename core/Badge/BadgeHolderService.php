<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

use Core\Member\MemberProfile;
use Core\Member\SectionService;

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
        usort($groups, static function (BadgeHolders $a, BadgeHolders $b) use ($sectionRank, $compare): int {
            $byKind = self::kindRank($a->badge) <=> self::kindRank($b->badge);
            if ($byKind !== 0) {
                return $byKind;
            }
            if ($a->badge->referentSectionId !== null && $b->badge->referentSectionId !== null) {
                $bySection = ($sectionRank[$a->badge->referentSectionId] ?? PHP_INT_MAX)
                    <=> ($sectionRank[$b->badge->referentSectionId] ?? PHP_INT_MAX);
                if ($bySection !== 0) {
                    return $bySection;
                }
            }

            return $compare($a->badge->name, $b->badge->name);
        });

        return $groups;
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
