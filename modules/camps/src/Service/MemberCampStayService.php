<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Config\ScoutYearService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\Module\MemberCampStayProvider;
use Core\Module\MemberCampStayView;
use Core\Service\DateInput;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;

/**
 * This module's answer to core's « où est-elle partie » hook
 * (Core\Module\MemberCampStayProvider, ARCHITECTURE.md §7.4).
 *
 * **The inference, stated plainly.** Nothing here records a camp's
 * participants one by one: `camp_camps` links to *sections*. So a stay
 * counts as this member's when their section went on it during a scout
 * year in which they belonged to that section — core's
 * `member_section_periods` crossed with this module's
 * `camp_camp_sections`. That is what the site actually knows. A block
 * claiming a real roster would be inventing one.
 *
 * **A stay carries no scout year**, only dates — or, for half of what a
 * unit remembers about its own past, a bare year. Placing it is
 * therefore ScoutYearService::labelForDate() on its END date, the same
 * rule the whole site uses. A `year_only` stay is read as the scout year
 * ENDING in that year: a grand camp happens in July or August, so "2012"
 * means 2011-2012. It is a convention, not a fact, and it is the least
 * wrong one available — the alternative, dropping those stays, would
 * silently empty the block for exactly the old camps this feature exists
 * to remember.
 */
class MemberCampStayService implements MemberCampStayProvider
{
    public function __construct(
        private CampRepository $camps,
        private PlaceRepository $places,
        private SectionMembershipRepository $memberships,
        private SectionService $sections,
        private ScoutYearService $scoutYears
    ) {
    }

    /**
     * @return list<MemberCampStayView>
     */
    public function getCampStays(int $memberId): array
    {
        $periods = $this->memberships->findAllForMember($memberId);
        if ($periods === []) {
            return [];
        }

        // (scout year label, section id) => the section's name. The key is
        // the label rather than the id because a stay carries a date, not
        // a scout_year_id, and a label is what a date resolves to.
        $wasThere = [];
        $sectionIds = [];
        foreach ($periods as $period) {
            $scoutYear = $this->scoutYears->findById($period->scoutYearId);
            $section = $this->sections->getSection($period->sectionId);
            if ($scoutYear === null || $section === null) {
                // A year or a section that no longer resolves is skipped
                // rather than rendered as a blank row, same as the
                // section history above it on the page.
                continue;
            }

            $label = (string) $scoutYear['label'];
            $wasThere[$label . ':' . $period->sectionId] = (string) ($section['name'] ?? $section['desk_code']);
            $sectionIds[$period->sectionId] = true;
        }
        if ($sectionIds === []) {
            return [];
        }

        $camps = $this->camps->findBySectionIds(array_keys($sectionIds));

        // Place names in one query rather than one per stay: a member of
        // ten years brings back twenty rows.
        $placeNames = [];
        foreach ($this->places->findByIds(array_map(static fn(Camp $c): int => $c->placeId, $camps)) as $place) {
            $placeNames[$place->id] = $place->name;
        }

        $views = [];
        foreach ($camps as $camp) {
            $label = self::scoutYearLabelOf($camp);
            if ($label === null) {
                continue;
            }

            foreach ($camp->sectionIds as $sectionId) {
                $sectionName = $wasThere[$label . ':' . $sectionId] ?? null;
                if ($sectionName === null) {
                    continue;
                }

                $views[] = new MemberCampStayView(
                    $placeNames[$camp->placeId] ?? 'Lieu inconnu',
                    CampLabels::dateRange($camp->startDate, $camp->endDate, $camp->yearOnly),
                    $sectionName,
                    $label,
                    '/chefs/camps/sejours/' . $camp->id
                );

                // One line per stay even when two of the member's sections
                // both went: the reader wants where they went, not how
                // many rows the join produced.
                break;
            }

            if (count($views) >= MemberCampStayProvider::LIMIT) {
                break;
            }
        }

        return $views;
    }

    /**
     * The scout year a stay falls in, or null when it carries neither a
     * readable end date nor a year — in which case the site does not know
     * when it happened and cannot honestly attach it to anybody.
     */
    private static function scoutYearLabelOf(Camp $camp): ?string
    {
        $end = DateInput::fromStorage($camp->endDate);
        if ($end !== null) {
            return ScoutYearService::labelForDate($end);
        }

        if ($camp->yearOnly !== null) {
            // See the class docblock: a bare year is read as the scout
            // year ENDING in it.
            return sprintf('%d-%d', $camp->yearOnly - 1, $camp->yearOnly);
        }

        return null;
    }
}
