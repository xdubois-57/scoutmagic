<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\SpreadsheetResponse;
use Core\Journal\JournalService;
use Core\Member\MemberYearService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestExportService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Twig\Environment;

/**
 * "Configuration > Inscriptions" — the module spec's "page de gestion":
 * capacities/year code (iteration 4) plus, since iteration 5, the whole
 * staff-facing overview of a scout year's requests — capacity verification
 * table, request list (searchable/filterable), the two encarts ("non
 * rapprochées" / "non clôturées"), and the two bulk actions. Age brackets
 * are read-only here (federation-fixed, see AgeBracketRepository) — there
 * is nothing to configure about them, only capacities per branch/year.
 * Row-level actions on a single request (accept/refuse/link/emails/notes...)
 * live in Controller\RegistrationRequestController's fiche instead.
 */
class RegistrationConfigController extends AbstractController
{
    /**
     * No federation branch spans more than this many years — bounds the
     * capacity grid so the template can render a fixed number of columns
     * without JS row add/remove (module spec: "minimal" admin screen).
     */
    private const MAX_YEARS_IN_BRANCH = 4;

    /**
     * Content key for the page's own editable explanatory block (module
     * spec Deliverable 1's "bloc de texte modifiable") — deliberately
     * distinct from the hardcoded state legend below it, which is never
     * editable since it documents behavior the code itself enforces.
     */
    private const NOTICE_CONTENT_KEY = 'registration_management_notice';

    /**
     * Waitlist management: the master switch, and the two ratio thresholds
     * that only mean anything while it is on. All three are declared in
     * module.json and stored in `settings` like any other — they are
     * *surfaced* here, inside the capacity box they belong to, rather than
     * owned here, exactly as the form's open/close state and the recurring
     * schedule already are.
     */
    private const SETTING_WAITLIST_ENABLED = 'registration_waitlist_enabled';
    private const SETTING_THRESHOLD_AVAILABLE = 'registration_waitlist_threshold_available';
    private const SETTING_THRESHOLD_LIMITED = 'registration_waitlist_threshold_limited';

    public function __construct(
        protected Environment $twig,
        private AgeBracketRepository $ageBracketRepository,
        private SlotCapacityRepository $slotCapacityRepository,
        private RegistrationYearCodeRepository $yearCodeRepository,
        private ScoutYearResolver $scoutYearResolver,
        private ScoutYearService $scoutYearService,
        private RegistrationRequestRepository $requestRepository,
        private SlotService $slotService,
        private SectionService $sectionService,
        private EditableContentService $editableContentService,
        private RequestStatusService $statusService,
        private JournalService $journalService,
        private SettingService $settingService,
        private RequestExportService $requestExportService
    ) {
    }

    /**
     * GET /config/inscriptions
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        // The module's default capacity is WRITTEN, not merely displayed:
        // a slot that has no row yet gets one here, so the grid, the
        // capacity-verification table and the public page all read the
        // same stored number. Seeding on this page rather than at module
        // activation is deliberate — `age_branches` is filled by the Desk
        // import, which typically happens well after the module is
        // switched on, so activation is simply too early to know which
        // slots exist. It runs once per missing slot and never rewrites an
        // existing row (Service\SlotService::seedMissingCapacities()), so
        // a chief who cleared a box keeps their "pas de limite".
        $this->slotService->seedMissingCapacities();

        [$requestedYearId, $statusFilter, $search] = $this->readListFilters($request);

        return $this->render('@registration/config.html.twig', $this->buildPageContext(
            $requestedYearId,
            $statusFilter,
            $search
        ));
    }

    /**
     * GET /config/inscriptions/export — the request list as an .xlsx.
     *
     * `role_min: admin`, the same floor as the page it lives on: an
     * export takes the role of its page, never a rung below.
     *
     * **It exports exactly what the screen shows**, filters included — it
     * re-reads the same three query parameters and goes through the same
     * row builder and the same filter as index() does, rather than
     * re-deriving a list that could drift from it. That is also why the
     * button carries the count: exporting 200 requests while looking at
     * the 12 pending ones is a surprise, and the reverse is worse.
     *
     * @param array<string, string> $params
     */
    public function export(Request $request, array $params): Response
    {
        [$requestedYearId, $statusFilter, $search] = $this->readListFilters($request);

        $selectedYear = $this->resolveSelectedYear($requestedYearId);
        $rows = $this->buildFilteredRequestRows((int) $selectedYear['id'], $selectedYear, $statusFilter, $search);

        // Counters and the year only. Never the search text — it is
        // typically a child's or a parent's name — and never a row's
        // contents, exactly like Modules\News\Controller\FormController
        // journals its own export (AGENTS.md § Security checklist §4).
        $this->journalService->log(
            'registration',
            'registration_requests_exported',
            'info',
            'Export des demandes d\'inscription',
            [
                'scout_year_id' => (int) $selectedYear['id'],
                'request_count' => count($rows),
                'status_filter' => $statusFilter ?? 'all',
                'search_used' => $search !== null,
            ],
            (int) AuthSession::getUserAccountId()
        );

        return SpreadsheetResponse::download(
            $this->requestExportService->buildSpreadsheet($rows),
            'demandes-inscription-' . $this->fileNameSlug((string) $selectedYear['label']) . '.xlsx'
        );
    }

    /**
     * The three query parameters the request list reads, normalised once
     * so index() and export() cannot read them differently.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    private function readListFilters(Request $request): array
    {
        $requestedYearId = (int) $request->getQuery('year', '0');
        $statusFilter = (string) $request->getQuery('status', '');
        $search = trim((string) $request->getQuery('q', ''));

        return [
            $requestedYearId > 0 ? $requestedYearId : null,
            $statusFilter !== '' ? $statusFilter : null,
            $search !== '' ? $search : null,
        ];
    }

    /**
     * A scout year label as a filename fragment: "2026-2027" survives,
     * anything else becomes a dash rather than reaching the Content-
     * Disposition header verbatim.
     */
    private function fileNameSlug(string $label): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9-]+/', '-', $label);

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'annee';
    }

    /**
     * POST /config/inscriptions — saves the capacity box: the grid itself,
     * the waitlist switch that governs it, and the two ratio thresholds
     * that only mean anything while that switch is on. Age brackets (entry
     * age/duration per branch) are no longer configured here —
     * Repository\AgeBracketRepository resolves them directly from
     * Core\Member\MemberYearService::BRANCHES, the same central federation
     * age ranges member_stats already uses, so there is nothing to save
     * for them.
     *
     * One box, one « Enregistrer » (design.md §7.13): the switch and the
     * numbers under it only mean something together — a capacity grid
     * saved without the level thresholds that read it is a half-applied
     * form — so this is the "group of fields" shape, not the
     * one-independent-control-saves-on-change one.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/inscriptions')) !== null) {
            return $guard;
        }

        // A body that isn't shaped like the grid must never be interpreted:
        // `capacity=x` used to produce string-offset access (PHP warnings)
        // and then silently reset every configured capacity to 0. A
        // malformed submission is refused instead of destroying the
        // existing configuration.
        $capacities = $request->getBody('capacity', []);
        if (!is_array($capacities)) {
            FlashMessage::set('error', 'Données de capacité invalides.');
            return $this->redirect('/config/inscriptions');
        }

        $waitlistEnabled = (string) $request->getBody('waitlist_enabled', '0') === '1';

        // The two thresholds are rendered only while the switch is already
        // on, so a submission that turns the waitlist OFF — and equally one
        // that turns it back ON — carries neither of them. An ABSENT field
        // is not an emptied one: the stored values are left exactly as they
        // are, and come back unchanged. Only fields actually submitted are
        // validated, and an inconsistent pair refuses the whole save rather
        // than applying half of it.
        /** @var array<string, string> $thresholdWrites setting key => value, empty when nothing was submitted */
        $thresholdWrites = [];
        $rawAvailable = $request->getBody('threshold_available', null);
        $rawLimited = $request->getBody('threshold_limited', null);
        if ($waitlistEnabled && ($rawAvailable !== null || $rawLimited !== null)) {
            $available = self::parseThreshold($rawAvailable);
            $limited = self::parseThreshold($rawLimited);
            if ($available === null || $limited === null || $limited >= $available) {
                FlashMessage::set(
                    'error',
                    'Seuils invalides : indiquez deux nombres entre 0 et 1, le seuil « attente limitée » '
                    . 'devant être inférieur au seuil « places disponibles ». Rien n\'a été enregistré.'
                );
                return $this->redirect('/config/inscriptions');
            }
            $thresholdWrites = [
                self::SETTING_THRESHOLD_AVAILABLE => self::formatThreshold($available),
                self::SETTING_THRESHOLD_LIMITED => self::formatThreshold($limited),
            ];
        }

        foreach ($this->ageBracketRepository->findAllOrdered() as $bracket) {
            $branchCapacities = $capacities[$bracket->ageBranchId] ?? null;
            if (!is_array($branchCapacities)) {
                continue;
            }
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $this->slotCapacityRepository->upsert(
                    $bracket->ageBranchId,
                    $yearInBranch,
                    self::parseCapacity($branchCapacities[$yearInBranch] ?? null)
                );
            }
        }

        $this->settingService->set(self::SETTING_WAITLIST_ENABLED, $waitlistEnabled ? '1' : '0', 'registration');
        foreach ($thresholdWrites as $settingKey => $settingValue) {
            $this->settingService->set($settingKey, $settingValue, 'registration');
        }

        FlashMessage::set('success', 'Configuration enregistrée.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * One capacity box, read the way the column stores it: **an empty box
     * is NULL — "pas de limite" — and never 0.**
     *
     * `(int) $value`, which this used to be, is exactly the trap
     * schema.sql warns about: it turns an empty field, a missing key and
     * any stray non-numeric body value into a 0, and a 0 is a branch
     * announced full. A typed `0` still means that, on purpose; nothing
     * else does.
     */
    private static function parseCapacity(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return max(0, (int) $trimmed);
    }

    /**
     * A waitlist threshold is a ratio in [0, 1]. Null means "not a usable
     * ratio", which save() turns into a refusal rather than into a number
     * that would silently change how full every branch looks. A comma is
     * accepted as the decimal separator — the field is French, and 0,5 is
     * what a chief types.
     */
    private static function parseThreshold(mixed $value): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = str_replace(',', '.', trim((string) $value));
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $ratio = (float) $trimmed;

        return ($ratio < 0 || $ratio > 1) ? null : $ratio;
    }

    /**
     * Back to the plain dot-decimal string the setting stores and
     * Service\SlotService reads with a `(float)` cast — never a
     * locale-formatted one.
     */
    private static function formatThreshold(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio, 4, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * POST /config/inscriptions/code/regenerate — new active code for the
     * current public scout year.
     *
     * @param array<string, string> $params
     */
    public function regenerateCode(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/inscriptions')) !== null) {
            return $guard;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->regenerate((int) $publicYear['id']);

        FlashMessage::set('success', 'Nouveau code généré.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/code/deactivate — closes in-year
     * registration for the current public scout year.
     *
     * @param array<string, string> $params
     */
    public function deactivateCode(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/inscriptions')) !== null) {
            return $guard;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->deactivate((int) $publicYear['id']);

        FlashMessage::set('success', 'Code désactivé.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/toggle-open — immediate manual open/close
     * of the public form, independent of the recurring schedule below (see
     * Task\OpenRegistrationHandler's docblock: a manual toggle never
     * touches the applied-on markers, so it never interferes with next
     * year's automatic transition).
     *
     * @param array<string, string> $params
     */
    public function toggleOpen(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/inscriptions')) !== null) {
            return $guard;
        }

        $isOpen = $this->settingService->get('registration_form_open', 'registration', '0') === '1';
        $this->settingService->set('registration_form_open', $isOpen ? '0' : '1', 'registration');

        $this->journalService->log(
            'registration',
            $isOpen ? 'registration_form_manually_closed' : 'registration_form_manually_opened',
            'info',
            $isOpen ? 'Formulaire d\'inscription fermé manuellement' : 'Formulaire d\'inscription ouvert manuellement'
        );

        FlashMessage::set('success', $isOpen ? 'Formulaire fermé.' : 'Formulaire ouvert.');
        return $this->redirect('/config/inscriptions');
    }

    /**
     * POST /config/inscriptions/schedule — saves the recurring MM-JJ
     * open/close dates. Module.json-declared settings carry no validation
     * regex (Module\ModuleManager::load()'s registration loop only passes
     * key/default/type/label/description/moduleId to SettingService::
     * register()), so the MM-DD format is validated here instead.
     *
     * @param array<string, string> $params
     */
    public function saveSchedule(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/config/inscriptions')) !== null) {
            return $guard;
        }

        $openAt = trim((string) $request->getBody('scheduled_open_at', ''));
        $closeAt = trim((string) $request->getBody('scheduled_close_at', ''));

        if (!$this->isValidMonthDayOrEmpty($openAt) || !$this->isValidMonthDayOrEmpty($closeAt)) {
            FlashMessage::set(
                'error',
                'Date invalide : utilisez le format MM-JJ (ex. 09-30), ou laissez vide. '
                . 'Le 29 février n\'est pas accepté — il n\'existe pas chaque année ; choisissez le 28.'
            );
            return $this->redirect('/config/inscriptions');
        }

        $this->settingService->set('registration_scheduled_open_at', $openAt, 'registration');
        $this->settingService->set('registration_scheduled_close_at', $closeAt, 'registration');

        FlashMessage::set('success', 'Programmation enregistrée.');
        return $this->redirect('/config/inscriptions');
    }

    private function isValidMonthDayOrEmpty(string $monthDay): bool
    {
        if ($monthDay === '') {
            return true;
        }
        if (preg_match('/^(\d{2})-(\d{2})$/', $monthDay, $matches) !== 1) {
            return false;
        }

        // 02-29 is refused outright: this is a RECURRING date, and the day
        // simply doesn't exist three years out of four. It used to be
        // accepted (checkdate against a leap reference year), so a schedule
        // set on it silently never fired on a non-leap year.
        if ($matches[1] === '02' && $matches[2] === '29') {
            return false;
        }

        return checkdate((int) $matches[1], (int) $matches[2], 2023);
    }

    /**
     * POST /config/inscriptions/bulk/refuse — refuses every non-final
     * request of the selected year in one action. Never available for a
     * past (consultation-only) year.
     *
     * @param array<string, string> $params
     */
    public function bulkRefuse(Request $request, array $params): Response
    {
        return $this->bulkTransition($request, 'refuse');
    }

    /**
     * POST /config/inscriptions/bulk/withdraw — same as bulkRefuse() but
     * marks every non-final request "retirée" instead.
     *
     * @param array<string, string> $params
     */
    public function bulkWithdraw(Request $request, array $params): Response
    {
        return $this->bulkTransition($request, 'withdraw');
    }

    private function bulkTransition(Request $request, string $action): Response
    {
        $yearId = (int) $request->getBody('scout_year_id', '0');

        if (($guard = $this->guardCsrf($request, '/config/inscriptions?year=' . $yearId)) !== null) {
            return $guard;
        }

        if ($yearId <= 0 || $this->isPastYear($yearId)) {
            FlashMessage::set('error', 'Action impossible : année invalide ou passée (consultation seule).');
            return $this->redirect('/config/inscriptions?year=' . $yearId);
        }

        $count = 0;
        foreach ($this->requestRepository->findNonFinalForYear($yearId) as $registrationRequest) {
            try {
                if ($action === 'refuse') {
                    $this->statusService->refuse($registrationRequest);
                } else {
                    $this->statusService->withdraw($registrationRequest);
                }
                $count++;
            } catch (RegistrationException) {
                // Left the non-final set between the read above and here — skip it.
            }
        }

        $this->journalService->log(
            'registration',
            'registration_bulk_action',
            'info',
            $action === 'refuse'
                ? 'Refus en masse des demandes non clôturées'
                : 'Retrait en masse des demandes non clôturées',
            ['scout_year_id' => $yearId, 'count' => $count]
        );

        FlashMessage::set('success', $count . ' demande(s) traitée(s).');
        return $this->redirect('/config/inscriptions?year=' . $yearId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPageContext(?int $requestedYearId, ?string $statusFilter, ?string $search): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $brackets = $this->ageBracketRepository->findAllOrdered();
        $capacities = $this->slotCapacityRepository->findAllAsMap();

        $selectedYear = $this->resolveSelectedYear($requestedYearId);
        $selectedYearId = (int) $selectedYear['id'];
        $isPastYear = $selectedYear['start_date'] < $publicYear['start_date'];

        $capacityBreakdown = $this->slotService->capacityBreakdownForYear(
            $selectedYearId,
            (string) $selectedYear['label'],
            (int) $publicYear['id']
        );

        $requestRows = $this->buildFilteredRequestRows($selectedYearId, $selectedYear, $statusFilter, $search);

        $years = $this->resolveSelectableYears($publicYear);

        return [
            'brackets' => $brackets,
            'capacities' => $capacities,
            'max_years_in_branch' => self::MAX_YEARS_IN_BRANCH,
            'csrf_token' => CsrfGuard::generateToken(),
            'public_year_label' => $publicYear['label'],
            'year_code' => $this->yearCodeRepository->findForYear((int) $publicYear['id']),

            'notice_content' => $this->editableContentService->get(self::NOTICE_CONTENT_KEY, ''),
            'notice_content_key' => self::NOTICE_CONTENT_KEY,

            'registration_form_open' => $this->settingService->get('registration_form_open', 'registration', '0') === '1',
            'scheduled_open_at' => (string) $this->settingService->get('registration_scheduled_open_at', 'registration', ''),
            'scheduled_close_at' => (string) $this->settingService->get('registration_scheduled_close_at', 'registration', ''),

            // Waitlist management, surfaced inside the capacity box rather
            // than hidden away in Configuration > Réglages. When it is off,
            // the two thresholds and the waitlist columns of the capacity
            // table are not rendered at all — they describe a mechanism
            // that is not running (their stored values are untouched).
            'waitlist_enabled' => $this->settingService->get(self::SETTING_WAITLIST_ENABLED, 'registration', '1') === '1',
            'threshold_available' => (string) $this->settingService->get(self::SETTING_THRESHOLD_AVAILABLE, 'registration', '0.5'),
            'threshold_limited' => (string) $this->settingService->get(self::SETTING_THRESHOLD_LIMITED, 'registration', '0.1'),
            'default_capacity' => SlotService::DEFAULT_CAPACITY,

            'selectable_years' => $years['selectable'],
            'target_year_id' => (int) $years['target']['id'],
            'current_year_id' => (int) $publicYear['id'],
            'selected_year' => $selectedYear,
            'is_past_year' => $isPastYear,

            'capacity_breakdown' => $capacityBreakdown,

            'request_rows' => $requestRows,
            'status_filter' => $statusFilter,
            'search' => $search,

            'unreconciled' => $this->requestRepository->findUnreconciledAcceptedForYear($selectedYearId),
            'non_final_count' => count($this->requestRepository->findNonFinalForYear($selectedYearId)),
        ];
    }

    /**
     * Which scout year the request list is looking at — the explicitly
     * requested one when it is selectable, the target year otherwise.
     * Shared by the page and its export so both always answer the same
     * `?year=`.
     *
     * @return array{id: int, label: string, start_date: string, end_date: string}
     */
    private function resolveSelectedYear(?int $requestedYearId): array
    {
        $years = $this->resolveSelectableYears($this->scoutYearResolver->getCurrentPublicYear());

        return $this->pickSelectedYear($years['selectable'], $requestedYearId) ?? $years['target'];
    }

    /**
     * The request list for one year, built and filtered exactly once —
     * the page renders these rows and the export writes the same ones,
     * which is the whole reason this is a method rather than two copies.
     *
     * @param array{id: int, label: string, start_date: string, end_date: string} $selectedYear
     * @return array<int, array<string, mixed>>
     */
    private function buildFilteredRequestRows(int $selectedYearId, array $selectedYear, ?string $statusFilter, ?string $search): array
    {
        $referenceYear = SlotMath::referenceCalendarYear(
            MemberYearService::referenceYearFromScoutYearLabel((string) $selectedYear['label']),
            $this->slotService->referenceMonthDay()
        );

        $rows = $this->buildRequestRows(
            $this->requestRepository->findAllForYear($selectedYearId),
            $this->ageBracketRepository->findAllOrdered(),
            $referenceYear,
            $this->sectionLabels()
        );

        return $this->filterRows($rows, $statusFilter, $search);
    }

    /**
     * @param array{id: int, label: string, start_date: string, end_date: string} $publicYear
     * @return array{
     *   public: array{id: int, label: string, start_date: string, end_date: string},
     *   target: array{id: int, label: string, start_date: string, end_date: string},
     *   selectable: array<int, array{id: int, label: string, start_date: string, end_date: string}>
     * }
     */
    private function resolveSelectableYears(array $publicYear): array
    {
        $targetLabel = ScoutYearService::nextLabel($publicYear['label']);
        $targetYearId = $this->scoutYearService->ensureYear($targetLabel);
        $targetYear = $this->scoutYearService->findById($targetYearId) ?? $publicYear;

        // Consultation-only past years still in the database, plus the
        // current and target years — never a year beyond the target
        // (module spec: "jamais future au-delà de N+1").
        $selectable = array_values(array_filter(
            $this->scoutYearService->getAll(),
            static fn(array $year) => $year['start_date'] <= $targetYear['start_date']
        ));
        usort($selectable, static fn(array $a, array $b) => $b['start_date'] <=> $a['start_date']);

        return ['public' => $publicYear, 'target' => $targetYear, 'selectable' => $selectable];
    }

    /**
     * @param array<int, array{id: int, label: string, start_date: string, end_date: string}> $selectableYears
     * @return array{id: int, label: string, start_date: string, end_date: string}|null
     */
    private function pickSelectedYear(array $selectableYears, ?int $requestedYearId): ?array
    {
        if ($requestedYearId === null) {
            return null;
        }
        foreach ($selectableYears as $year) {
            if ($year['id'] === $requestedYearId) {
                return $year;
            }
        }

        return null;
    }

    private function isPastYear(int $yearId): bool
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $year = $this->scoutYearService->findById($yearId);

        return $year !== null && $year['start_date'] < $publicYear['start_date'];
    }

    /**
     * @return array<int, string> section id => display label
     */
    private function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->sectionService->getAllWithBranches(true) as $section) {
            $labels[$section['id']] = $section['name'] ?? $section['desk_code'];
        }

        return $labels;
    }

    /**
     * @param array<\Modules\Registration\Repository\RegistrationRequest> $requests
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array<int, string> $sectionLabels
     * @return array<int, array<string, mixed>>
     */
    private function buildRequestRows(array $requests, array $brackets, int $referenceYear, array $sectionLabels): array
    {
        // One grouped count for the whole year rather than a query per row —
        // this list routinely carries a couple of hundred requests.
        $siblingCounts = $this->requestRepository->countSiblingsForRequests(
            array_map(static fn($r) => $r->id, $requests)
        );

        $rows = [];
        foreach ($requests as $registrationRequest) {
            $slot = SlotMath::slotForBirthDate($brackets, $registrationRequest->birthDate, $referenceYear);

            $rows[] = [
                'request' => $registrationRequest,
                'slot_label' => $this->slotLabel($brackets, $slot),
                'intended_section_label' => $registrationRequest->intendedSectionId !== null
                    ? ($sectionLabels[$registrationRequest->intendedSectionId] ?? '—')
                    : null,
                // Only the export reads this one today — the screen has
                // no column for the family's own wish, which the staff's
                // "section prévue" answers. It belongs on the row rather
                // than in the exporter so both read one source.
                'desired_section_label' => $registrationRequest->desiredSectionId !== null
                    ? ($sectionLabels[$registrationRequest->desiredSectionId] ?? '')
                    : '',
                'sibling_count' => $siblingCounts[$registrationRequest->id] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows, ?string $statusFilter, ?string $search): array
    {
        if ($statusFilter !== null) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row) => $row['request']->status === $statusFilter
            ));
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle) {
                $registrationRequest = $row['request'];
                $haystack = mb_strtolower(
                    $registrationRequest->childFirstName . ' ' . $registrationRequest->childLastName
                    . ' ' . $registrationRequest->parentName
                );

                return mb_stripos($haystack, $needle) !== false;
            }));
        }

        return $rows;
    }

    /**
     * @param array<\Modules\Registration\Repository\AgeBracket> $brackets
     * @param array{age_branch_id: int, year_in_branch: int}|null $slot
     */
    private function slotLabel(array $brackets, ?array $slot): string
    {
        if ($slot === null) {
            return 'Non déterminé';
        }
        foreach ($brackets as $bracket) {
            if ($bracket->ageBranchId === $slot['age_branch_id']) {
                return $bracket->branchLabel . ' — ' . $slot['year_in_branch'] . 'ᵉ année';
            }
        }

        return 'Non déterminé';
    }
}
