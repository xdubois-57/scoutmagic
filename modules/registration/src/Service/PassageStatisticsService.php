<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Member\SectionService;
use Modules\Registration\Api\ProjectedPerson;
use Modules\Registration\Api\ProjectedPopulationProvider;

/**
 * The statistics box at the top of the Passage page (spec §8): per branch,
 * per section, what next year looks like as the evening's decisions are
 * made.
 *
 * **No third headcount.** Every figure here comes from
 * `Api\ProjectedPopulationProvider` (ARCHITECTURE.md §7.5), which is
 * `ForecastService`'s own projection — the same one the Prévisions page
 * shows. This class groups it by branch, resolves the colours through
 * `SectionService::colorForSection()` (the one source), and nothing else.
 * A page that disagreed with Prévisions about next year's Louveteaux would
 * be worse than either page being wrong alone.
 *
 * **Two scopes, and they answer different questions.**
 * - `SCOPE_PROJECTED` (the default) is the whole projected population:
 *   what each section will actually hold.
 * - `SCOPE_ARRIVALS` is only the people this page assigns — a branch
 *   change (`passage`) or an accepted registration (`registration`).
 *   The animés who simply stay are excluded, which is why the page carries
 *   a warning next to it: the view is for checking that arrivals are
 *   spread evenly, never for judging whether the sections are balanced.
 *   Real target-year Desk rows (`desk`) are not arrivals either — they are
 *   already encoded facts, and nobody assigns them here.
 *
 * **The third gender bucket is « Non renseigné », not « Autre ».** The
 * projection classifies an absent, empty or unrecognised gender the same
 * way, so this box says the honest thing about what it knows rather than
 * asserting a third gender nobody entered.
 *
 * Computed ONCE per request, over the whole unit — never once per row of
 * the tables underneath, which on a passage evening would be a full
 * projection per line.
 */
class PassageStatisticsService
{
    public const SCOPE_PROJECTED = 'projected';
    public const SCOPE_ARRIVALS = 'arrivals';

    /** The origins that count as somebody arriving into a section. */
    private const ARRIVAL_ORIGINS = ['passage', 'registration'];

    public function __construct(
        private SectionService $sectionService,
        private ?ProjectedPopulationProvider $projectedPopulation = null
    ) {
    }

    /**
     * Everything the box shows, both scopes at once.
     *
     * Both, deliberately: the scope switch is a control on a page whose
     * numbers are refreshed by a save round trip, and computing only the
     * selected scope would mean either a second request when somebody
     * flips it or a stale half. The projection is walked once and tallied
     * twice, which costs nothing next to walking it at all.
     *
     * @return array{
     *   available: bool,
     *   branches: array<int, array{label: string, sections: array<int, array{id: int, label: string, color: string, scopes: array<string, array{total: int, certain: int, hypothesis: int, male: int, female: int, unknown: int}>}>, scopes: array<string, array{max: int, total: int}>}>,
     *   unassigned: array<string, int>
     * }
     */
    public function forTargetYear(int $targetScoutYearId): array
    {
        if ($this->projectedPopulation === null) {
            // The module that owns the projection is this one, so this
            // cannot happen in the composed application — but the service
            // is constructible without it, and a box that quietly showed
            // zeros would be worse than one that is not there.
            return ['available' => false, 'branches' => [], 'unassigned' => $this->emptyScopeCounts()];
        }

        $people = $this->projectedPopulation->projectedPopulation($targetScoutYearId);

        // includeHidden, like every other consumer of this projection: a
        // hidden-but-active section is an ordinary Passage destination, and
        // leaving it out would move its arrivals into no branch at all.
        $sections = $this->sectionService->getAllWithBranches(true);

        $sectionMeta = [];
        foreach ($sections as $section) {
            $sectionMeta[(int) $section['id']] = [
                'branch_sort_order' => (int) $section['branch_sort_order'],
                'branch_label' => (string) $section['branch_name'],
                'label' => (string) ($section['name'] ?? $section['desk_code']),
                'color' => SectionService::colorForSection($section),
            ];
        }

        /** @var array<int, array<int, array<string, array<string, int>>>> $tally branch sort order => section id => scope => counts */
        $tally = [];
        $unassigned = $this->emptyScopeCounts();

        foreach ($people as $person) {
            $scopes = $this->scopesFor($person);

            if ($person->sectionId === null || !isset($sectionMeta[$person->sectionId])) {
                foreach ($scopes as $scope) {
                    $unassigned[$scope]++;
                }
                continue;
            }

            $branchOrder = $sectionMeta[$person->sectionId]['branch_sort_order'];
            foreach ($scopes as $scope) {
                $tally[$branchOrder][$person->sectionId][$scope] ??= $this->emptySectionCounts();
                $this->add($tally[$branchOrder][$person->sectionId][$scope], $person);
            }
        }

        ksort($tally);

        $branches = [];
        foreach ($tally as $branchOrder => $sectionsInBranch) {
            $branches[] = $this->buildBranch($branchOrder, $sectionsInBranch, $sectionMeta);
        }

        return ['available' => true, 'branches' => $branches, 'unassigned' => $unassigned];
    }

    /**
     * Which scopes this person is counted in. Everyone is in the projected
     * scope; only somebody this page assigns is also an arrival.
     *
     * @return array<int, string>
     */
    private function scopesFor(ProjectedPerson $person): array
    {
        return in_array($person->origin, self::ARRIVAL_ORIGINS, true)
            ? [self::SCOPE_PROJECTED, self::SCOPE_ARRIVALS]
            : [self::SCOPE_PROJECTED];
    }

    /**
     * @param array<int, array<string, array<string, int>>> $sectionsInBranch
     * @param array<int, array{branch_sort_order: int, branch_label: string, label: string, color: string}> $sectionMeta
     * @return array{label: string, sections: array<int, array{id: int, label: string, color: string, scopes: array<string, array{total: int, certain: int, hypothesis: int, male: int, female: int, unknown: int}>}>, scopes: array<string, array{max: int, total: int}>}
     */
    private function buildBranch(int $branchOrder, array $sectionsInBranch, array $sectionMeta): array
    {
        $label = '';
        $sections = [];
        $scopeSummary = [];
        foreach ([self::SCOPE_PROJECTED, self::SCOPE_ARRIVALS] as $scope) {
            $scopeSummary[$scope] = ['max' => 0, 'total' => 0];
        }

        // Ordered by the unit's own section order, not by whoever was
        // tallied first — the same reasoning as the projection's own
        // per-section totals.
        foreach ($sectionMeta as $sectionId => $meta) {
            if ($meta['branch_sort_order'] !== $branchOrder || !isset($sectionsInBranch[$sectionId])) {
                continue;
            }

            $label = $meta['branch_label'];
            $scopes = [];
            foreach ([self::SCOPE_PROJECTED, self::SCOPE_ARRIVALS] as $scope) {
                /** @var array{total: int, certain: int, hypothesis: int, male: int, female: int, unknown: int} $counts */
                $counts = $sectionsInBranch[$sectionId][$scope] ?? $this->emptySectionCounts();
                $scopes[$scope] = $counts;
                // The bar is comparable BETWEEN sections of one branch, so
                // its scale is the branch's biggest section — not the
                // unit's, which would flatten a whole branch of small
                // sections into invisible stubs.
                $scopeSummary[$scope]['max'] = max($scopeSummary[$scope]['max'], $counts['total']);
                $scopeSummary[$scope]['total'] += $counts['total'];
            }

            $sections[] = [
                'id' => $sectionId,
                'label' => $meta['label'],
                'color' => $meta['color'],
                'scopes' => $scopes,
            ];
        }

        return ['label' => $label, 'sections' => $sections, 'scopes' => $scopeSummary];
    }

    /**
     * @param array<string, int> $counts
     */
    private function add(array &$counts, ProjectedPerson $person): void
    {
        $counts['total']++;
        $person->certain ? $counts['certain']++ : $counts['hypothesis']++;

        // 'other' from the projection means "absent, empty, or a value
        // nobody recognised" — every one of which is « non renseigné »,
        // never an assertion about the person.
        $counts[match ($person->gender) {
            'male' => 'male',
            'female' => 'female',
            default => 'unknown',
        }]++;
    }

    /**
     * @return array{total: int, certain: int, hypothesis: int, male: int, female: int, unknown: int}
     */
    private function emptySectionCounts(): array
    {
        return ['total' => 0, 'certain' => 0, 'hypothesis' => 0, 'male' => 0, 'female' => 0, 'unknown' => 0];
    }

    /**
     * @return array<string, int>
     */
    private function emptyScopeCounts(): array
    {
        return [self::SCOPE_PROJECTED => 0, self::SCOPE_ARRIVALS => 0];
    }
}
