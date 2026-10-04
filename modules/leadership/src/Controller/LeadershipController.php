<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Controller;

use Core\Export\TabularSpreadsheet;
use Core\Exception\UserFacingMessage;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\Role;
use Core\Service\TextNormalizerService;
use Core\View\EditableContentService;
use Modules\Leadership\FormationStep;
use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\FormationLevelMappingRepository;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Service\FormationLevelResolver;
use Modules\Leadership\Service\ObligationsService;
use Modules\Leadership\Service\StewardService;
use Modules\Leadership\Service\TrainingService;
use Modules\Leadership\Value\PersonLine;
use Modules\MassMail\Api\MassMailDraftInterface;
use Twig\Environment;

/**
 * The module's pages — a dashboard, three lists and the configuration.
 * Every one of them is `role_min: admin`
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
        private EditableContentService $editableContentService,
        private JournalService $journalService,
        /** Null when mass_mail is off: the draft button is then not offered (§7.5). */
        private ?MassMailDraftInterface $massMailDraft = null
    ) {
    }

    /**
     * Every list the pages draw, by the id its markup carries: which page
     * it is on, and its title — the draft's label and the export's
     * « Liste » column (#727).
     */
    private const LISTS = [
        'training-to-convince' => ['page' => 'training', 'title' => 'À convaincre de commencer'],
        'training-to-finish' => ['page' => 'training', 'title' => 'Parcours à terminer'],
        'obligations-birthdays' => ['page' => 'obligations', 'title' => 'Anniversaires des 20 ans'],
        'obligations-candidates' => ['page' => 'obligations', 'title' => 'Candidats au dernier import'],
        'stewards-registrations' => ['page' => 'stewards', 'title' => 'Intendants inscrits'],
        'stewards-under-age' => ['page' => 'stewards', 'title' => 'Intendants trop jeunes'],
    ];

    /** The sub-pages that carry lists, and the title of each one's export. */
    private const LIST_PAGES = [
        'training' => 'Formations',
        'obligations' => 'Obligations',
        'stewards' => 'Intendants',
    ];

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

        return $this->render(
            '@leadership/index.html.twig',
            $this->withFooter(
                $context,
                [
                    'training_count' => count($toConvince) + count($toFinish),
                    'obligations_count' => count($birthdays) + count($candidates),
                    'stewards_count' => count($stewards),
                    'summer_regime' => $this->stewardService->isSummerRegime($context['today']),
                ]
            )
        );
    }

    /**
     * GET /admin/leadership/training — the unit note, the two contact
     * lists and the staffing situation. The vocabulary mapping has its own
     * page (configuration()), which this one links to while a Desk value
     * is unrecognised.
     *
     * @param array<string, string> $params
     */
    public function training(Request $request, array $params): Response
    {
        $context = $this->context();

        return $this->render(
            '@leadership/training.html.twig',
            $this->withFooter(
                $context,
                [
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
                    // Above the ratio rather than beside it: the number these
                    // people stopped counting towards is the one a chief reads
                    // first (roadmap IT-20).
                    'unspecified_brevets' => $this->trainingService->unspecifiedBrevetCount(
                        $context['staff'],
                        $context['resolver']
                    ),
                    'unresolved_levels' => $this->trainingService->unresolvedLevels(
                        $context['scout_year_id'],
                        $context['resolver']
                    ),
                ]
            )
        );
    }

    /**
     * GET /admin/leadership/configuration — the Desk formation wordings:
     * those still to attach first, then the decisions already recorded.
     * Each select saves itself (FormationMappingController, JSON).
     *
     * @param array<string, string> $params
     */
    public function configuration(Request $request, array $params): Response
    {
        $context = $this->context();

        return $this->render(
            '@leadership/configuration.html.twig',
            $this->withFooter(
                $context,
                [
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
                ]
            )
        );
    }

    /**
     * GET /admin/leadership/{page}/export — the lists of one page as a
     * spreadsheet: what the page shows, phone included, one row per line.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $page = (string) ($params['page'] ?? '');
        if (!isset(self::LIST_PAGES[$page])) {
            return $this->notFound();
        }

        $context = $this->context();
        $rows = [];
        foreach (self::LISTS as $listId => $list) {
            if ($list['page'] !== $page) {
                continue;
            }
            foreach ($this->linesFor($listId, $context) as $line) {
                $rows[] = [
                    $list['title'],
                    $line->fullName,
                    $line->totem,
                    $line->sectionName,
                    $line->detail,
                    $line->note,
                    $line->email,
                    $line->phone,
                    $line->days !== null
                        ? ($line->daysDirection === PersonLine::DAYS_UNTIL ? 'dans ' : 'depuis ') . $line->days . ' j'
                        : null,
                ];
            }
        }

        // Which page and how many lines, never who: the journal holds no
        // personal data (AGENTS.md).
        $this->journalService->log(
            'leadership',
            'leadership_list_exported',
            'info',
            'Export d\'une page Encadrement',
            ['page' => $page, 'row_count' => count($rows)],
            AuthSession::getUserAccountId()
        );

        $title = self::LIST_PAGES[$page];
        $spreadsheet = TabularSpreadsheet::buildSpreadsheet(
            ['Liste', 'Nom', 'Totem', 'Section', 'Détail', 'Remarque', 'E-mail', 'Téléphone', 'Échéance'],
            $rows,
            $title
        );

        return \Core\Http\SpreadsheetResponse::download(
            $spreadsheet,
            'encadrement-' . self::slug($title) . '-' . $context['scout_year_label'] . '.xlsx'
        );
    }

    /**
     * POST /admin/leadership/draft — a mail-merge draft to the people of
     * one list who have an address. Nothing is sent: the visitor lands on
     * the composer. The list is computed here again, never taken from the
     * request — a hand-crafted POST cannot add an address to it.
     *
     * @param array<string, string> $params
     */
    public function draft(Request $request, array $params): Response
    {
        $listId = (string) $request->getBody('list', '');
        $list = self::LISTS[$listId] ?? null;
        // An unknown list goes back to the dashboard: '/admin/leadership/'
        // with a trailing slash is a route of nothing.
        $back = $list === null ? '/admin/leadership' : '/admin/leadership/' . $list['page'];
        if (($guard = $this->guardCsrf($request, $back)) !== null) {
            return $guard;
        }
        if ($list === null || $this->massMailDraft === null) {
            return $this->notFound();
        }

        $rows = [];
        foreach ($this->linesFor($listId, $this->context()) as $line) {
            if ($line->email === null || $line->email === '') {
                continue;
            }
            $rows[] = [
                'email' => $line->email,
                'values' => ['Nom' => $line->fullName, 'Section' => (string) $line->sectionName],
            ];
        }

        try {
            $url = $this->massMailDraft->createMergeDraft(
                'Encadrement — ' . $list['title'],
                '',
                ['Nom', 'Section'],
                $rows,
                AuthSession::getRole(),
                AuthSession::getEmail() ?? '',
                AuthSession::getUserAccountId()
            );
        } catch (\Throwable $e) {
            FlashMessage::set(
                'error',
                UserFacingMessage::from(
                    $e,
                    "Le brouillon n'a pas pu être créé. Vérifiez que le publipostage est configuré."
                )
            );

            return $this->redirect($back);
        }

        $this->journalService->log(
            'leadership',
            'leadership_list_draft_created',
            'info',
            'Brouillon de publipostage depuis une liste Encadrement',
            ['list' => $listId, 'recipient_count' => count($rows)],
            AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', "Le brouillon est prêt — relisez-le, il n'a pas été envoyé.");

        return $this->redirect($url);
    }

    /**
     * The lines of one list, by id — the same call its page makes.
     *
     * @param array<string, mixed> $context
     * @return list<PersonLine>
     */
    private function linesFor(string $listId, array $context): array
    {
        return match ($listId) {
            'training-to-convince' => $this->trainingService->toConvince(
                $context['staff'],
                $context['scout_year_id'],
                $context['scout_year_label'],
                $context['previous_scout_year_id'],
                $context['resolver']
            ),
            'training-to-finish' => $this->trainingService->toFinish($context['staff'], $context['resolver']),
            'obligations-birthdays' => $this->obligationsService
                ->upcomingAdultBirthdays($context['staff'], $context['today']),
            'obligations-candidates' => $this->obligationsService->candidates($context['staff'], $context['today']),
            'stewards-registrations' => $this->stewardService->registrations(
                $context['staff'],
                $context['scout_year_id'],
                $context['today']
            ),
            'stewards-under-age' => $this->stewardService->underAgeStewards($context['staff'], $context['today']),
            default => [],
        };
    }

    private static function slug(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', TextNormalizerService::fold($title)), '-');
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

        return $this->render(
            '@leadership/obligations.html.twig',
            $this->withFooter(
                $context,
                [
                    'birthdays' => $this->obligationsService
                        ->upcomingAdultBirthdays($context['staff'], $context['today']),
                    'candidates' => $this->obligationsService->candidates($context['staff'], $context['today']),
                    // An empty birthday block means "nobody turns 20 soon" only
                    // when every birth date is known; this is how many people it
                    // could say nothing about.
                    'without_birth_date' => $this->obligationsService->countWithoutBirthDate($context['staff']),
                    'alert_weeks' => LeadershipRules::ADULT_AGE_ALERT_WEEKS,
                    'adult_age' => LeadershipRules::ADULT_AGE,
                ]
            )
        );
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

        return $this->render(
            '@leadership/stewards.html.twig',
            $this->withFooter(
                $context,
                [
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
                ]
            )
        );
    }

    /**
     * Everything all four pages need, read once: the effective scout year,
     * the staff rows, the mapping-aware resolver, and today.
     *
     * @return array{
     *     scout_year_id: int,
     *     scout_year_label: string,
     *     previous_scout_year_id: ?int,
     *     staff: list<\Modules\Leadership\Value\StaffFunctionRow>,
     *     resolver: FormationLevelResolver,
     *     today: \DateTimeImmutable,
     *     last_import_at: ?string
     * }
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
            'draft_available' => $this->massMailDraft !== null,
            'scout_year_label' => $context['scout_year_label'],
            'last_import_at' => $context['last_import_at'],
            'rules_version' => LeadershipRules::VERSION,
            'rules_verified_on' => LeadershipRules::VERIFIED_ON,
        ];
    }
}
