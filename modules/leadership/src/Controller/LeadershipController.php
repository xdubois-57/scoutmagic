<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\View\EditableContentService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\ObligationsService;
use Modules\Leadership\Service\StewardService;
use Modules\Leadership\Service\TrainingService;
use Twig\Environment;

/**
 * The module's four read-only pages. Every one of them is `role_min: admin`
 * on the Espace chefs d'U menu, and every one of them states the date of
 * the import it is reading — nothing here is fresher than that, and a page
 * that did not say so would be inviting a chief to act on a picture from
 * three weeks ago.
 */
class LeadershipController extends AbstractController
{
    /**
     * `editable_contents` key of the unit note. One fixed key, so there is
     * one note for the unit — never one per member and never one per
     * section (see the module's ARCHITECTURE section for why).
     */
    public const UNIT_NOTE_KEY = 'leadership.unit_note';

    public function __construct(
        protected Environment $twig,
        private LeadershipRepository $repository,
        private FormationLevelMappingRepository $mappingRepository,
        private FormationLevelResolver $resolver,
        private TrainingService $trainingService,
        private ObligationsService $obligationsService,
        private StewardService $stewardService,
        private ScoutYearResolver $scoutYearResolver,
        private EditableContentService $editableContentService
    ) {
    }

    /**
     * GET /admin/leadership — three cards, one per sub-page, one number
     * each. A landing page and nothing more: every number on it is computed
     * by the same service that owns the page it links to, so the card and
     * the page can never disagree.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $context = $this->context();

        $toConvince = $this->trainingService->toConvince(
            $context['staff'],
            $context['scout_year_id'],
            $context['scout_year_label'],
            $context['previous_scout_year_id'],
            $context['resolver']
        );
        $toFinish = $this->trainingService->toFinish($context['staff'], $context['resolver']);
        $birthdays = $this->obligationsService->upcomingAdultBirthdays($context['staff'], $context['today']);
        $candidates = $this->obligationsService->candidates($context['staff'], $context['today']);
        $stewards = $this->stewardService->registrations(
            $context['staff'],
            $context['scout_year_id'],
            $context['today']
        );

        return $this->render('@leadership/index.html.twig', $this->withFooter($context, [
            'training_count' => count($toConvince) + count($toFinish),
            'obligations_count' => count($birthdays) + count($candidates),
            'stewards_count' => count($stewards),
            'summer_regime' => $this->stewardService->isSummerRegime($context['today']),
        ]));
    }

    /**
     * GET /admin/leadership/training — the unit note, the two contact
     * lists, the staffing situation, and the mapping block.
     *
     * @param array<string, string> $params
     */
    public function training(Request $request, array $params): Response
    {
        $context = $this->context();

        return $this->render('@leadership/training.html.twig', $this->withFooter($context, [
            'breadcrumb_trail' => $this->hubTrail(),
            'unit_note_key' => self::UNIT_NOTE_KEY,
            'unit_note' => $this->editableContentService->get(self::UNIT_NOTE_KEY),
            'to_convince' => $this->trainingService->toConvince(
                $context['staff'],
                $context['scout_year_id'],
                $context['scout_year_label'],
                $context['previous_scout_year_id'],
                // So somebody who arrives with a T1 already behind them is
                // on "à terminer" and not on "à convaincre de commencer".
                $context['resolver']
            ),
            // With a single imported year there is nothing to compare
            // against, so the first-year half of the list cannot be
            // computed at all. An empty list would read as "nobody to
            // convince", which is a different and wrong statement.
            'history_too_short' => $context['previous_scout_year_id'] === null,
            'to_finish' => $this->trainingService->toFinish($context['staff'], $context['resolver']),
            'section_situations' => $this->trainingService->sectionSituations(
                $context['staff'],
                $context['scout_year_id'],
                $context['resolver']
            ),
            'unresolved_levels' => $this->trainingService->unresolvedLevels(
                $context['scout_year_id'],
                $context['resolver']
            ),
            'decided_levels' => $this->trainingService->decidedLevels(
                $this->mappingRepository->findAllRows(),
                $context['scout_year_id']
            ),
            'assignable_steps' => array_map(
                static fn (FormationStep $step): array => ['value' => $step->value, 'label' => $step->label()],
                FormationStep::assignable()
            ),
        ]));
    }

    /**
     * GET /admin/leadership/obligations — 20th birthdays first (the only
     * anticipable case), then the candidates Desk flagged.
     *
     * @param array<string, string> $params
     */
    public function obligations(Request $request, array $params): Response
    {
        $context = $this->context();

        return $this->render('@leadership/obligations.html.twig', $this->withFooter($context, [
            'breadcrumb_trail' => $this->hubTrail(),
            'birthdays' => $this->obligationsService->upcomingAdultBirthdays($context['staff'], $context['today']),
            'candidates' => $this->obligationsService->candidates($context['staff'], $context['today']),
            // An empty birthday block means "nobody turns 20 soon" only
            // when every birth date is known; this is how many people it
            // could say nothing about.
            'without_birth_date' => $this->obligationsService->countWithoutBirthDate($context['staff']),
            'alert_weeks' => LeadershipRules::ADULT_AGE_ALERT_WEEKS,
            'adult_age' => LeadershipRules::ADULT_AGE,
        ]));
    }

    /**
     * GET /admin/leadership/stewards — a countdown from September to May, a
     * reminder from June to August.
     *
     * @param array<string, string> $params
     */
    public function stewards(Request $request, array $params): Response
    {
        $context = $this->context();
        $summer = $this->stewardService->isSummerRegime($context['today']);

        return $this->render('@leadership/stewards.html.twig', $this->withFooter($context, [
            'breadcrumb_trail' => $this->hubTrail(),
            'summer_regime' => $summer,
            'registrations' => $this->stewardService->registrations(
                $context['staff'],
                $context['scout_year_id'],
                $context['today']
            ),
            'under_age' => $this->stewardService->underAgeStewards($context['staff'], $context['today']),
            'free_days' => LeadershipRules::STEWARD_FREE_DAYS,
            'warning_days' => LeadershipRules::STEWARD_WARNING_DAYS,
            'critical_days' => LeadershipRules::STEWARD_CRITICAL_DAYS,
            'min_age' => LeadershipRules::STEWARD_MIN_AGE,
        ]));
    }

    /**
     * Everything all four pages need, read once: the effective scout year,
     * the staff rows, the mapping-aware resolver, and today.
     *
     * @return array{scout_year_id: int, scout_year_label: string, previous_scout_year_id: ?int, staff: list<\Modules\Leadership\Value\StaffFunctionRow>, resolver: FormationLevelResolver, today: \DateTimeImmutable, last_import_at: ?string}
     */
    private function context(): array
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        return [
            'scout_year_id' => $effectiveYear->id,
            'scout_year_label' => $effectiveYear->label,
            'previous_scout_year_id' => $this->repository->findPreviousScoutYearId($effectiveYear->id),
            'staff' => $this->repository->findStaffFunctions($effectiveYear->id),
            'resolver' => $this->resolver->withMapping($this->mappingRepository->findAll()),
            'today' => new \DateTimeImmutable('today'),
            'last_import_at' => $this->repository->findLastImportAt($effectiveYear->id),
        ];
    }

    /**
     * The footer every page carries: which import the figures come from,
     * which scout year, and how old the thresholds are.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withFooter(array $context, array $data): array
    {
        return $data + [
            'scout_year_label' => $context['scout_year_label'],
            'last_import_at' => $context['last_import_at'],
            'rules_version' => LeadershipRules::VERSION,
            'rules_verified_on' => LeadershipRules::VERIFIED_ON,
        ];
    }

    /**
     * The hub, as a real link, for the three pages hanging off it.
     *
     * Only `/admin/leadership` carries a menu entry, so its three
     * sub-pages had « Espace chefs d'U › Formations » and no way back to
     * the page whose three cards sent the visitor there — the breadcrumb
     * being the site's only back affordance (design.md §7.3), that was a
     * dead end reachable in one click.
     *
     * @return array<int, array{label: string, url: string}>
     */
    private function hubTrail(): array
    {
        return [['label' => 'Encadrement', 'url' => '/admin/leadership']];
    }
}
