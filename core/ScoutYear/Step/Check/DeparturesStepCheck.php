<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearTransitionStepRepository;
use Core\ScoutYear\Step\StepAction;
use Core\ScoutYear\Step\StepCheck;
use Core\ScoutYear\Step\StepCheckProvider;
use Core\ScoutYear\Step\StepContext;
use Modules\Registration\Api\ReenrollmentCampaignState;
use Modules\Registration\Api\ScoutYearMember;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;

/**
 * Step « departures », human: where the reenrollment campaign of the
 * target year stands, and who is announced as leaving.
 *
 * **The one hard block on a human step** (chantier « Année scoute » §2.3):
 * while the campaign is scheduled or running, $markRefusal says why, and
 * ScoutYearTransitionService::markStep() asks this check again on the mark
 * request itself — a forged POST is refused the same way. Finished means
 * started and no longer open, as the module's own clock and switch say,
 * never inferred from a count of answers.
 *
 * A mark already made is never undone by the campaign reopening: the
 * decision was a human's. The reopening is then a point to check.
 *
 * No campaign configured for the target at all (the module answers null)
 * is not a reason to refuse: there is nothing to wait for. It is a point
 * to check, with the way to the campaign settings.
 *
 * $details: campaign (null, or {state, opens_on, closes_on} with d/m/Y
 * dates), leavers (list of {name, section, url}), target_label.
 */
final class DeparturesStepCheck implements StepCheckProvider
{
    public function __construct(
        private ?ScoutYearRegistrationReviewProvider $review,
        private ScoutYearTransitionStepRepository $marks,
        private SectionService $sections
    ) {
    }

    public function stepKey(): string
    {
        return 'departures';
    }

    public function check(StepContext $context): StepCheck
    {
        $departures = StepAction::linkTo('departures', 'Voir les départs', '/departs');
        if ($this->review === null) {
            return new StepCheck(primaryAction: $departures, actions: [$departures]);
        }

        $target = $context->targetLabel();
        $campaign = $this->review->reenrollmentCampaign($context->targetId(), $context->now);
        $details = [
            'campaign' => $campaign === null ? null : [
                'state' => $campaign->state,
                'opens_on' => CheckText::date($campaign->opensOn),
                'closes_on' => CheckText::date($campaign->closesOn),
            ],
            'leavers' => $this->leavers($this->review->announcedLeavers($context->targetId())),
            'target_label' => $target,
        ];

        $manage = StepAction::linkTo('campaign', 'Gérer la campagne', '/config/reinscription');

        if ($campaign === null) {
            $settings = StepAction::linkTo(
                'campaign_settings',
                'Configurer la campagne',
                '/config/reinscription/reglages'
            );

            return new StepCheck(
                pointsToCheck: ["Aucune campagne de réinscription n'est configurée pour {$target}"],
                primaryAction: $departures,
                actions: [$departures, $settings],
                details: $details
            );
        }

        if ($campaign->isFinished()) {
            return new StepCheck(primaryAction: $departures, actions: [$departures, $manage], details: $details);
        }

        $points = [];
        if (isset($this->marks->findByYear($context->targetId())[$this->stepKey()])) {
            $points[] = $campaign->state === ReenrollmentCampaignState::OPEN
                ? 'Campagne rouverte après le marquage'
                : "Étape marquée alors que la campagne n'a pas encore commencé";
        }

        return new StepCheck(
            pointsToCheck: $points,
            primaryAction: $manage,
            actions: [$manage, $departures],
            markRefusal: $this->refusal($campaign, $target),
            details: $details
        );
    }

    /**
     * The two sentences of §2.3, one per state that refuses the mark. A
     * date the campaign does not have is left out rather than invented.
     */
    private function refusal(ReenrollmentCampaignState $campaign, string $target): string
    {
        $then = " L'étape pourra être marquée une fois la campagne terminée.";

        if ($campaign->state === ReenrollmentCampaignState::NOT_STARTED) {
            $opens = CheckText::date($campaign->opensOn);

            return "La campagne de réinscription {$target} n'a pas encore commencé"
                . ($opens !== null ? " (ouverture le {$opens})" : '') . '.' . $then;
        }

        $closes = CheckText::date($campaign->closesOn);

        return "La campagne de réinscription {$target} est en cours"
            . ($closes !== null ? " (fermeture le {$closes})" : '') . '.' . $then;
    }

    /**
     * @param list<ScoutYearMember> $leavers
     * @return list<array{name: string, section: ?string, url: string}>
     */
    private function leavers(array $leavers): array
    {
        $labels = CheckText::sectionLabels(
            $this->sections,
            array_map(static fn(ScoutYearMember $m): ?int => $m->sectionId, $leavers)
        );

        return array_map(static fn(ScoutYearMember $member): array => [
            'name' => $member->name,
            'section' => $member->sectionId !== null ? ($labels[$member->sectionId] ?? null) : null,
            'url' => CheckText::memberUrl($member->memberYearId),
        ], $leavers);
    }
}
