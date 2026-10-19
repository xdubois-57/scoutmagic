<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Registration\Api\MissingAcceptedRequest;
use Modules\Registration\Api\PassageMismatch;
use Modules\Registration\Api\ScoutYearMember;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;

/**
 * Step « desk_members », human: after a successful import of the target
 * year, what was prepared on the site against what Desk holds (chantier
 * « Année scoute » §2.5) — three lists given as help, never a verdict:
 * announced leavers still active, passages imported into another section
 * than the one chosen, accepted requests missing from the import.
 *
 * The comparison is the registration module's own
 * (ScoutYearRegistrationReviewProvider::preparedImportGaps()), called only
 * once the import exists — before it, every leaver would be « absent » and
 * every request « missing », so the step says it is waiting instead.
 * Without that module there is nothing prepared on the site to compare.
 *
 * $details: gaps (list of {kind: leaver|passage|request, label, name,
 * url}), imported_at; absent before the import.
 */
final class DeskMembersStepCheck implements StepCheckProvider
{
    public function __construct(
        private ?ScoutYearRegistrationReviewProvider $review,
        private SectionService $sections
    ) {
    }

    public function stepKey(): string
    {
        return 'desk_members';
    }

    public function check(StepContext $context): StepCheck
    {
        if ($this->review === null) {
            return StepCheck::none();
        }

        if (!$context->hasSuccessfulImport()) {
            return new StepCheck(pendingMessage: $context->waitingForImportMessage());
        }

        $gaps = $this->review->preparedImportGaps($context->targetId());
        $target = $context->targetLabel();
        $labels = CheckText::sectionLabels($this->sections, array_merge(
            ...array_map(
                static fn(PassageMismatch $p): array => [$p->destinationSectionId, $p->importedSectionId],
                $gaps->passagesElsewhere
            )
        ));

        $rows = [];
        foreach ($gaps->leaversStillActive as $member) {
            $rows[] = $this->row('leaver', "Annoncé partant, présent en {$target}", $member);
        }
        foreach ($gaps->passagesElsewhere as $passage) {
            $rows[] = [
                'kind' => 'passage',
                'label' => 'Passage vers ' . ($labels[$passage->destinationSectionId] ?? '?')
                    . ', importé en ' . ($labels[$passage->importedSectionId] ?? '?'),
                'name' => $passage->name,
                'url' => CheckText::memberUrl($passage->memberYearId),
            ];
        }
        foreach ($gaps->acceptedRequestsMissing as $request) {
            $rows[] = [
                'kind' => 'request',
                'label' => "Inscription acceptée, absente de l'import",
                'name' => $request->childName,
                'url' => '/config/inscriptions/demandes/' . $request->requestId,
            ];
        }

        return new StepCheck(
            pointsToCheck: $this->points(
                $gaps->leaversStillActive,
                $gaps->passagesElsewhere,
                $gaps->acceptedRequestsMissing
            ),
            details: ['gaps' => $rows, 'imported_at' => $context->latestTargetImport?->importedAt]
        );
    }

    /**
     * @return array{kind: string, label: string, name: string, url: string}
     */
    private function row(string $kind, string $label, ScoutYearMember $member): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'name' => $member->name,
            'url' => CheckText::memberUrl($member->memberYearId),
        ];
    }

    /**
     * @param list<ScoutYearMember> $leavers
     * @param list<PassageMismatch> $passages
     * @param list<MissingAcceptedRequest> $requests
     * @return list<string>
     */
    private function points(array $leavers, array $passages, array $requests): array
    {
        $points = [];
        if ($leavers !== []) {
            $points[] = CheckText::count(
                count($leavers),
                "partant présent dans l'import",
                "partants présents dans l'import"
            );
        }
        if ($passages !== []) {
            $points[] = CheckText::count(
                count($passages),
                'passage dans une autre section',
                'passages dans une autre section'
            );
        }
        if ($requests !== []) {
            $points[] = CheckText::count(
                count($requests),
                'inscription acceptée non retrouvée',
                'inscriptions acceptées non retrouvées'
            );
        }

        return $points;
    }
}
