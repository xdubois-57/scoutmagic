<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Badge\Badge;
use Core\Badge\BadgeHolder;
use Core\Badge\BadgeHolders;
use Core\Badge\BadgeHolderService;
use Core\Badge\BadgeRepository;
use Core\Badge\BadgeYearAggregate;
use Core\Member\SectionService;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;

/**
 * Step « badges », human — an informative view (chantier « Année scoute »
 * §2.6), no badge is required anywhere and nothing is compared with the
 * year before:
 * 1. per section checked, the badges worn in the target year with their
 *    holders (BadgeHolderService::sectionAggregateForYear());
 * 2. the default badges worn nowhere;
 * 3. the transversal badges worn in some sections and not in others.
 *
 * **A referent badge never makes a section look incomplete.** It is filed
 * under the Staff d'U, whose members wear it, and the transversal gaps
 * never consider it. One referent badge of a checked section that nobody
 * wears at all is the unit's gap, not that section's: it is listed with
 * the default badges worn nowhere, as the mock-up of #930 shows.
 *
 * The holdings are the target year's own, so the view waits for its
 * import.
 *
 * $details: sections (list of {label, badges: list of {name, holders:
 * list of {name, url}}}), unworn (list of badge names), gaps (list of
 * {badge, without: list of section labels}); absent before the import.
 */
final class BadgesStepCheck implements StepCheckProvider
{
    public function __construct(
        private SectionService $sections,
        private BadgeHolderService $holders,
        private BadgeRepository $badges
    ) {
    }

    public function stepKey(): string
    {
        return 'badges';
    }

    public function check(StepContext $context): StepCheck
    {
        $staffs = StepAction::linkTo('badges', 'Ouvrir Staffs', '/chefs/staffs');
        $holders = StepAction::linkTo('badge_holders', 'Porteurs des badges', '/admin/badges');
        $actions = [$staffs, $holders];

        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(
                primaryAction: $staffs,
                actions: $actions,
                pendingMessage: $context->waitingForImportMessage()
            );
        }

        $checked = CheckText::checkedSections($this->sections, $context->targetId(), withUnitStaff: false);
        $sectionIds = array_map(static fn($section): int => $section->sectionId, $checked);
        $aggregate = $this->holders->sectionAggregateForYear($context->targetId(), $sectionIds);

        $labels = [];
        foreach ($checked as $section) {
            $labels[$section->sectionId] = $section->label;
        }
        $labels += CheckText::sectionLabels($this->sections, array_keys($aggregate->bySection));

        $unworn = [...$aggregate->defaultBadgesUnworn, ...$this->referentBadgesUnworn($aggregate, $sectionIds)];

        $points = [];
        foreach ($unworn as $badge) {
            $points[] = "{$badge->name} attribué nulle part";
        }
        $gaps = [];
        foreach ($aggregate->transversalGaps as $gap) {
            $points[] = $gap->badge->name . ' absent dans '
                . CheckText::count(count($gap->sectionIdsWithout), 'section', 'sections');
            $gaps[] = [
                'badge' => $gap->badge->name,
                'without' => array_map(static fn(int $id): string => $labels[$id] ?? '?', $gap->sectionIdsWithout),
            ];
        }

        $rows = [];
        foreach ($aggregate->bySection as $sectionId => $groups) {
            $rows[] = ['label' => $labels[$sectionId] ?? '?', 'badges' => $this->badgeRows($groups)];
        }
        if ($aggregate->withoutSection !== []) {
            $rows[] = ['label' => 'Sans section', 'badges' => $this->badgeRows($aggregate->withoutSection)];
        }

        return new StepCheck(
            pointsToCheck: $points,
            primaryAction: $staffs,
            actions: $actions,
            details: [
                'sections' => $rows,
                'unworn' => array_map(static fn(Badge $badge): string => $badge->name, $unworn),
                'gaps' => $gaps,
            ]
        );
    }

    /**
     * The active referent badges of the checked sections that nobody wears
     * that year, wherever they would be filed.
     *
     * @param list<int> $sectionIds
     * @return list<Badge>
     */
    private function referentBadgesUnworn(BadgeYearAggregate $aggregate, array $sectionIds): array
    {
        $worn = [];
        foreach ([...array_merge(...array_values($aggregate->bySection)), ...$aggregate->withoutSection] as $group) {
            $worn[$group->badge->id] = true;
        }

        return array_values(array_filter(
            $this->badges->findAll(),
            static fn(Badge $badge): bool => $badge->referentSectionId !== null && $badge->isActive
                && $badge->isDefault && in_array($badge->referentSectionId, $sectionIds, true)
                && !isset($worn[$badge->id])
        ));
    }

    /**
     * @param list<BadgeHolders> $groups
     * @return list<array{name: string, holders: list<array{name: string, url: string}>}>
     */
    private function badgeRows(array $groups): array
    {
        return array_map(static fn(BadgeHolders $group): array => [
            'name' => $group->badge->name,
            'holders' => array_map(static fn(BadgeHolder $holder): array => [
                'name' => $holder->name,
                'url' => CheckText::memberUrl($holder->memberYearId),
            ], $group->holders),
        ], $groups);
    }
}
