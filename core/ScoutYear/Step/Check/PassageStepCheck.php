<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Registration\Api\PassageWithoutDestination;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;

/**
 * Step « passage »: how many animés change branch and who still has no
 * destination section, through the registration module's own contracts.
 * A human step — zero passage left undecided is a point less to check,
 * never the step done, and « aucun passage » is said as such.
 *
 * The total and the count come from ScoutYearPreparationProvider (the
 * year Passage itself targets, which is the target year), the names from
 * ScoutYearRegistrationReviewProvider::passagesWithoutDestination() — the
 * same people, the Passage page's own list.
 *
 * Both are null exactly when the module is off, in which case the step is
 * not on the page and this is never called; it still answers nothing
 * rather than fail.
 *
 * $details: passage_count, unassigned_count, unassigned (list of {name,
 * section, branch_year}).
 */
final class PassageStepCheck implements StepCheckProvider
{
    public function __construct(
        private ?ScoutYearPreparationProvider $preparation,
        private ?ScoutYearRegistrationReviewProvider $review,
        private SectionService $sections
    ) {
    }

    public function stepKey(): string
    {
        return 'passage';
    }

    public function check(StepContext $context): StepCheck
    {
        $action = StepAction::linkTo('passage', 'Ouvrir Passage', '/passage');
        if ($this->preparation === null) {
            return new StepCheck(primaryAction: $action, actions: [$action]);
        }

        $total = $this->preparation->countPassages();
        $unassigned = $total > 0 ? $this->preparation->countUnassignedPassages() : 0;
        $people = $unassigned > 0 && $this->review !== null
            ? $this->review->passagesWithoutDestination($context->targetId())
            : [];

        return new StepCheck(
            pointsToCheck: $unassigned > 0
                ? [CheckText::count($unassigned, "animé sans section d'arrivée", "animés sans section d'arrivée")]
                : [],
            primaryAction: $action,
            actions: [$action],
            details: [
                'passage_count' => $total,
                'unassigned_count' => $unassigned,
                'unassigned' => $this->rows($people),
            ]
        );
    }

    /**
     * @param list<PassageWithoutDestination> $people
     * @return list<array{name: string, section: ?string, branch_year: string}>
     */
    private function rows(array $people): array
    {
        $labels = CheckText::sectionLabels(
            $this->sections,
            array_map(static fn(PassageWithoutDestination $p): ?int => $p->currentSectionId, $people)
        );

        return array_map(static fn(PassageWithoutDestination $person): array => [
            'name' => $person->name,
            'section' => $person->currentSectionId !== null ? ($labels[$person->currentSectionId] ?? null) : null,
            'branch_year' => $person->branchYearLabel,
        ], $people);
    }
}
