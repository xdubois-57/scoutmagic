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
use Modules\Registration\Service\PriorityOrder;
use Modules\Registration\Service\PriorityOrderService;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\RecurringMonthDay;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestExportService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use Modules\Registration\Task\OpenRegistrationHandler;
use Core\Config\SettingException;
use Twig\Environment;

/**
 * « Formulaire d'inscription » (Espace chefs d'U) — the module spec's
 * "page de gestion", in four sub-pages joined by one nav rail
 * (views/config/_nav.html.twig, issue #843 D1):
 *
 * - **Tableau de bord** (`/config/inscriptions`, index()): four figures
 *   and the capacity verification table of the target year;
 * - **Demandes** (`/config/inscriptions/demandes`, requests()): the
 *   staff's notes, the state legend, the request list of one scout year
 *   (searchable, filterable, exportable) and the bulk actions over its
 *   non-final requests;
 * - **Gabarits** (`/config/inscriptions/gabarits`): the request
 *   e-mails' templates, in Controller\RegistrationTemplateController;
 * - **Réglages** (`/config/inscriptions/reglages`, settings()): opening
 *   and the recurring schedule, the in-year code, the capacity box (the
 *   waitlist switch, the thresholds, the capacity grid) and the
 *   « Priorités » of the recommendation (issue #843, IT-03).
 *
 * Every POST of this controller keeps the address it always had and sends
 * the visitor back to the sub-page its form lives on. Age brackets are
 * read-only here (federation-fixed, see AgeBracketRepository) — there is
 * nothing to configure about them, only capacities per branch/year.
 * Row-level actions on a single request (accept/refuse/link/emails/notes...)
 * live in Controller\RegistrationRequestController's fiche instead, itself
 * a sub-page of « Demandes ».
 */
class RegistrationConfigController extends AbstractController
{
    /**
     * Content key for the page's own editable explanatory block (module
     * spec Deliverable 1's "bloc de texte modifiable") — deliberately
     * distinct from the hardcoded state legend below it, which is never
     * editable since it documents behavior the code itself enforces.
     */
    private const NOTICE_CONTENT_KEY = 'registration_management_notice';

    /** The sub-pages every POST below sends the visitor back to. */
    private const PATH_REQUESTS = '/config/inscriptions/demandes';
    private const PATH_SETTINGS = '/config/inscriptions/reglages';

    /**
     * Waitlist management: the master switch. It and the two occupancy
     * thresholds it governs (Service\SlotService::SETTING_THRESHOLD_YES /
     * SETTING_THRESHOLD_MAYBE) are declared in module.json and stored in
     * `settings` like any other — they are *surfaced* here, inside the
     * capacity box they belong to, rather than owned here, exactly as the
     * form's open/close state and the recurring schedule already are.
     */
    private const SETTING_WAITLIST_ENABLED = SlotService::SETTING_WAITLIST_ENABLED;

    /**
     * The mock-up's sentence when « Ordre d'arrivée » is dropped into
     * « Toujours Oui » — the browser says it, and so does the server when a
     * submission carries it anyway.
     */
    private const MESSAGE_RANK_ONLY_IN_ALWAYS =
        "« Ordre d'arrivée de la demande » ne peut pas être « Toujours Oui » : elle ne sert qu'à classer.";

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
        private RequestExportService $requestExportService,
        private PriorityOrderService $priorityOrderService
    ) {
    }

    /**
     * GET /config/inscriptions — « Tableau de bord »: four figures, then
     * the capacity verification table of the target year.
     *
     * The figures cover the two scout years a decision can still be taken
     * for — the current public year (in-year registrations made with the
     * code) and the target year — never a past one, which is
     * consultation-only (issue #843 R8). Their definitions live in
     * Repository\RegistrationRequestRepository, not here.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->seedDefaultCapacities();

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $targetYear = $this->resolveSelectableYears($publicYear)['target'];
        $liveYearIds = array_values(array_unique([(int) $publicYear['id'], (int) $targetYear['id']]));

        return $this->render('@registration/config/dashboard.html.twig', [
            'registration_form_open' => $this->isFormOpen(),
            'pending_count' => $this->requestRepository->countPendingForYears($liveYearIds),
            'decision_emails_to_send_count' => $this->requestRepository->countDecisionEmailsToSendForYears(
                $liveYearIds
            ),
            'accepted_not_in_desk_count' => $this->requestRepository->countAcceptedNotEncodedForYears($liveYearIds),

            // The capacity verification, exactly as it was rendered before
            // the page was split — same service call, same partial — on the
            // target year, the one the public form registers for.
            // `is_past_year` is false by construction: a past year's table
            // has no projection to show, and this page never shows one.
            'selected_year' => $targetYear,
            'is_past_year' => false,
            'capacity_breakdown' => $this->slotService->capacityBreakdownForYear(
                (int) $targetYear['id'],
                (string) $targetYear['label'],
                (int) $publicYear['id']
            ),
            'waitlist_enabled' => $this->isWaitlistEnabled(),
        ]);
    }

    /**
     * GET /config/inscriptions/demandes — « Demandes »: notes, state
     * legend, the request list of one scout year and its bulk actions.
     *
     * Declared in module.json BEFORE `/config/inscriptions/demandes/{id}`,
     * although the two cannot collide anyway: `{id}` only ever matches
     * digits (Core\Http\Router::placeholderPattern()).
     *
     * @param array<string, string> $params
     */
    public function requests(Request $request, array $params): Response
    {
        [$requestedYearId, $statusFilter, $search] = $this->readListFilters($request);

        return $this->render(
            '@registration/config/requests.html.twig',
            $this->buildRequestsContext($requestedYearId, $statusFilter, $search)
        );
    }

    /**
     * GET /config/inscriptions/reglages — « Réglages »: the public form's
     * opening and recurring schedule, the in-year code, the capacities.
     *
     * @param array<string, string> $params
     */
    public function settings(Request $request, array $params): Response
    {
        $this->seedDefaultCapacities();

        return $this->render('@registration/config/settings.html.twig', $this->buildSettingsContext());
    }

    /**
     * The module's default capacity is WRITTEN, not merely displayed: a
     * slot that has no row yet gets one here, so the grid, the capacity-
     * verification table and the public page all read the same stored
     * number. Seeding when a chief opens these pages rather than at module
     * activation is deliberate — `age_branches` is filled by the Desk
     * import, which typically happens well after the module is switched
     * on, so activation is simply too early to know which slots exist. It
     * runs once per missing slot and never rewrites an existing row
     * (Service\SlotService::seedMissingCapacities()), so a chief who
     * cleared a box keeps their "pas de limite". Both the dashboard (whose
     * table reads the capacities) and Réglages (whose grid edits them) do
     * it, so neither ever shows a slot the other has not seeded.
     */
    private function seedDefaultCapacities(): void
    {
        $this->slotService->seedMissingCapacities();
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
     * POST /config/inscriptions — saves the capacity grid, the « Capacités »
     * sub-box of the capacity box. Age brackets (entry age/duration per
     * branch) are not configured here — Repository\AgeBracketRepository
     * resolves them directly from Core\Member\MemberYearService::BRANCHES,
     * the same central federation age ranges member_stats already uses, so
     * there is nothing to save for them.
     *
     * One sub-box, one « Enregistrer » (issue #843, D11): the waitlist
     * switch saves on its own (saveWaitlist()), the thresholds with their
     * own button (saveThresholds()). A grid saved only in part is a
     * half-applied form, so one box that is neither empty nor a whole
     * number refuses the whole grid.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
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
            return $this->redirect(self::PATH_SETTINGS);
        }

        /** @var array<int, array{int, int, ?int}> $writes [branch id, year in branch, capacity] */
        $writes = [];
        foreach ($this->ageBracketRepository->findAllOrdered() as $bracket) {
            $branchCapacities = $capacities[$bracket->ageBranchId] ?? null;
            if (!is_array($branchCapacities)) {
                continue;
            }
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $raw = $branchCapacities[$yearInBranch] ?? '';
                if (!self::isCapacityInput($raw)) {
                    FlashMessage::set(
                        'error',
                        'Capacités invalides : indiquez un nombre entier, ou laissez la case vide. '
                        . 'Rien n\'a été enregistré.'
                    );
                    return $this->redirect(self::PATH_SETTINGS);
                }
                $writes[] = [$bracket->ageBranchId, $yearInBranch, self::parseCapacity($raw)];
            }
        }

        foreach ($writes as [$ageBranchId, $yearInBranch, $capacity]) {
            $this->slotCapacityRepository->upsert($ageBranchId, $yearInBranch, $capacity);
        }

        FlashMessage::set('success', 'Capacités enregistrées.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * POST /config/inscriptions/listes-attente — « Gérer les listes
     * d'attente », saved on change (design.md §7.13: one independent
     * control, no button) by public/assets/js/registration-capacity-
     * settings.js, which greys the thresholds out or back in on the answer.
     *
     * Turning it off changes nothing else: the thresholds and the
     * priorities keep their stored values, they only stop having an effect
     * — no level for the families, no recommendation for the staff.
     *
     * @param array<string, string> $params
     */
    public function saveWaitlist(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }
        if (!is_bool($data['enabled'] ?? null)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        $enabled = $data['enabled'];

        try {
            $this->settingService->set(self::SETTING_WAITLIST_ENABLED, $enabled ? '1' : '0', 'registration');
        } catch (SettingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
        $this->journalService->log(
            'registration',
            'registration_waitlist_toggled',
            'info',
            $enabled ? 'Gestion des listes d\'attente activée' : 'Gestion des listes d\'attente désactivée',
            ['enabled' => $enabled],
            (int) AuthSession::getUserAccountId()
        );

        return $this->json([
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Gestion des listes d\'attente activée.'
                : 'Gestion des listes d\'attente désactivée. Les seuils et les priorités sont conservés.',
        ]);
    }

    /**
     * POST /config/inscriptions/seuils — « Oui jusqu'à … % » and
     * « Peut-être jusqu'à … % de places occupées », the thresholds sub-box.
     *
     * Refused while waitlists are not managed: the sub-box is greyed out
     * and its button disabled then, and this page does not change values a
     * chief cannot see take effect. (Configuration > Réglages still edits
     * each key on its own, as it always has; a pair it leaves unusable is
     * read as the defaults and announced here — SlotService::
     * levelThresholds().) Both values or neither — an invalid pair saves
     * nothing (SettingService::setMany()).
     *
     * @param array<string, string> $params
     */
    public function saveThresholds(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        if (!$this->isWaitlistEnabled()) {
            FlashMessage::set(
                'error',
                'Les seuils ne se modifient pas tant que la gestion des listes d\'attente est coupée. '
                . 'Rien n\'a été enregistré.'
            );
            return $this->redirect(self::PATH_SETTINGS);
        }

        $yes = SlotMath::parseThresholdPercent($request->getBody('threshold_yes_percent', null));
        $maybe = SlotMath::parseThresholdPercent($request->getBody('threshold_maybe_percent', null));
        if ($yes === null || $maybe === null || !SlotMath::isValidThresholdPair($yes, $maybe)) {
            FlashMessage::set(
                'error',
                'Seuils invalides : indiquez deux nombres entiers de ' . SlotMath::THRESHOLD_MIN_PERCENT
                . ' à ' . SlotMath::THRESHOLD_MAX_PERCENT . ' %, le seuil « Oui » devant être inférieur au '
                . 'seuil « Peut-être ». Rien n\'a été enregistré.'
            );
            return $this->redirect(self::PATH_SETTINGS);
        }

        try {
            $this->settingService->setMany([
                SlotService::SETTING_THRESHOLD_YES => (string) $yes,
                SlotService::SETTING_THRESHOLD_MAYBE => (string) $maybe,
            ], 'registration');
        } catch (SettingException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect(self::PATH_SETTINGS);
        }

        $this->journalService->log(
            'registration',
            'registration_thresholds_saved',
            'info',
            'Seuils de la recommandation et du niveau public enregistrés',
            ['yes_percent' => $yes, 'maybe_percent' => $maybe],
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Seuils enregistrés.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * POST /config/inscriptions/priorites — the « Priorités » box: which
     * criterion sits in « Toujours Oui », « Classement » or « Désactivés »,
     * and in which order (Service\PriorityOrder).
     *
     * The three connected lists post `priority[always][]`,
     * `priority[rank][]` and `priority[off][]` in the order they show; an
     * empty zone posts nothing, which is an empty zone. Whatever arrives is
     * validated in full — every criterion exactly once, « Ordre d'arrivée »
     * never in « Toujours Oui » — and a refused order saves nothing.
     *
     * Saved whether or not waitlists are managed: the box stays editable,
     * and says it has no effect until they are (D3).
     *
     * @param array<string, string> $params
     */
    public function savePriorities(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $zones = $request->getBody('priority', []);
        $verdict = PriorityOrder::validate($zones);
        $order = PriorityOrder::fromZones($zones);
        if ($order === null) {
            FlashMessage::set(
                'error',
                $verdict === PriorityOrder::INVALID_RANK_ONLY_IN_ALWAYS
                    ? self::MESSAGE_RANK_ONLY_IN_ALWAYS . ' Rien n\'a été enregistré.'
                    : 'Priorités invalides : chaque critère doit figurer une seule fois, dans « Toujours Oui », '
                        . '« Classement » ou « Désactivés ». Rien n\'a été enregistré.'
            );
            return $this->redirect(self::PATH_SETTINGS);
        }

        $this->priorityOrderService->save($order);
        $this->journalService->log(
            'registration',
            'registration_priorities_saved',
            'info',
            'Priorités de la recommandation enregistrées',
            $order->toZones(),
            (int) AuthSession::getUserAccountId()
        );

        FlashMessage::set('success', 'Priorités enregistrées.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * Whether a capacity box holds something the grid can store: empty
     * (« pas de limite ») or a whole number of places, 0 included.
     */
    private static function isCapacityInput(mixed $value): bool
    {
        return is_scalar($value) && preg_match('/^\d{0,4}$/', trim((string) $value)) === 1;
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
     * POST /config/inscriptions/code/regenerate — new active code for the
     * current public scout year.
     *
     * @param array<string, string> $params
     */
    public function regenerateCode(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->regenerate((int) $publicYear['id']);

        FlashMessage::set('success', 'Nouveau code généré.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * POST /config/inscriptions/code/deactivate — closes in-year
     * registration for the current public scout year.
     *
     * @param array<string, string> $params
     */
    public function deactivateCode(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();
        $this->yearCodeRepository->deactivate((int) $publicYear['id']);

        FlashMessage::set('success', 'Code désactivé.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * POST /config/inscriptions/toggle-open — immediate manual open/close
     * of the public form, independent of the recurring schedule below.
     *
     * Deciding by hand also SETTLES whichever scheduled occurrence is
     * currently inside its catch-up window (Task\OpenRegistrationHandler
     * ::settleDueOccurrences()). Without that, a fresh installation —
     * where both markers are empty, so every occurrence in the window is
     * still pending — had the chief's manual open reversed by the next
     * hourly poll, under a journal line announcing an annual transition
     * nobody had programmed. Occurrences OUTSIDE the window, next year's
     * included, are untouched: the schedule keeps working.
     *
     * @param array<string, string> $params
     */
    public function toggleOpen(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $isOpen = $this->settingService->get('registration_form_open', 'registration', '0') === '1';
        $this->settingService->set('registration_form_open', $isOpen ? '0' : '1', 'registration');
        $settled = OpenRegistrationHandler::settleDueOccurrences($this->settingService);

        $this->journalService->log(
            'registration',
            $isOpen ? 'registration_form_manually_closed' : 'registration_form_manually_opened',
            'info',
            $isOpen ? 'Formulaire d\'inscription fermé manuellement' : 'Formulaire d\'inscription ouvert manuellement',
            $settled === [] ? [] : ['settled_occurrences' => $settled]
        );

        FlashMessage::set('success', $isOpen ? 'Formulaire fermé.' : 'Formulaire ouvert.');
        return $this->redirect(self::PATH_SETTINGS);
    }

    /**
     * POST /config/inscriptions/schedule — saves the recurring open/close
     * dates. The page edits them with native date pickers showing the next
     * occurrence; only the day and the month are stored, as "MM-DD"
     * (Service\RecurringMonthDay::fromSubmitted(), which also accepts the
     * bare "MM-DD" a browser without a date picker lets somebody type).
     * Module.json-declared settings carry no validation regex
     * (Module\ModuleManager::load()'s registration loop only passes
     * key/default/type/label/description/moduleId to SettingService::
     * register()), so the date is validated here instead — both fields, or
     * neither is saved.
     *
     * @param array<string, string> $params
     */
    public function saveSchedule(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, self::PATH_SETTINGS)) !== null) {
            return $guard;
        }

        $openAt = RecurringMonthDay::fromSubmitted((string) $request->getBody('scheduled_open_at', ''));
        $closeAt = RecurringMonthDay::fromSubmitted((string) $request->getBody('scheduled_close_at', ''));

        if ($openAt === null || $closeAt === null) {
            FlashMessage::set(
                'error',
                'Date invalide : choisissez une date existante, ou laissez le champ vide. '
                . 'Le 29 février n\'est pas accepté — il n\'existe pas chaque année ; choisissez le 28 février '
                . 'ou le 1er mars. Rien n\'a été enregistré.'
            );
            return $this->redirect(self::PATH_SETTINGS);
        }

        $this->settingService->set('registration_scheduled_open_at', $openAt, 'registration');
        $this->settingService->set('registration_scheduled_close_at', $closeAt, 'registration');

        FlashMessage::set('success', 'Programmation enregistrée.');
        return $this->redirect(self::PATH_SETTINGS);
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

        if (($guard = $this->guardCsrf($request, self::PATH_REQUESTS . '?year=' . $yearId)) !== null) {
            return $guard;
        }

        if ($yearId <= 0 || $this->isPastYear($yearId)) {
            FlashMessage::set('error', 'Action impossible : année invalide ou passée (consultation seule).');
            return $this->redirect(self::PATH_REQUESTS . '?year=' . $yearId);
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
        return $this->redirect(self::PATH_REQUESTS . '?year=' . $yearId);
    }

    /**
     * « Demandes »: the request list of one scout year and what sits
     * around it.
     *
     * @return array<string, mixed>
     */
    private function buildRequestsContext(?int $requestedYearId, ?string $statusFilter, ?string $search): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        $selectedYear = $this->resolveSelectedYear($requestedYearId);
        $selectedYearId = (int) $selectedYear['id'];
        $isPastYear = $selectedYear['start_date'] < $publicYear['start_date'];

        $years = $this->resolveSelectableYears($publicYear);

        return [
            'csrf_token' => CsrfGuard::generateToken(),

            'notice_content' => $this->editableContentService->get(self::NOTICE_CONTENT_KEY, ''),
            'notice_content_key' => self::NOTICE_CONTENT_KEY,

            'selectable_years' => $years['selectable'],
            'target_year_id' => (int) $years['target']['id'],
            'current_year_id' => (int) $publicYear['id'],
            'selected_year' => $selectedYear,
            'is_past_year' => $isPastYear,

            'request_rows' => $this->buildFilteredRequestRows($selectedYearId, $selectedYear, $statusFilter, $search),
            'status_filter' => $statusFilter,
            'search' => $search,

            'non_final_count' => count($this->requestRepository->findNonFinalForYear($selectedYearId)),
        ];
    }

    /**
     * « Réglages »: the form's opening and schedule, the in-year code and
     * the capacity box.
     *
     * @return array<string, mixed>
     */
    private function buildSettingsContext(): array
    {
        $publicYear = $this->scoutYearResolver->getCurrentPublicYear();

        return [
            'csrf_token' => CsrfGuard::generateToken(),
            'public_year_label' => $publicYear['label'],
            'year_code' => $this->yearCodeRepository->findForYear((int) $publicYear['id']),

            'registration_form_open' => $this->isFormOpen(),
            'scheduled_open' => $this->scheduleField(
                'registration_scheduled_open_at',
                'registration_scheduled_open_applied_on'
            ),
            'scheduled_close' => $this->scheduleField(
                'registration_scheduled_close_at',
                'registration_scheduled_close_applied_on'
            ),

            // Waitlist management, surfaced inside the capacity box rather
            // than hidden away in Configuration > Réglages. When it is off,
            // the waitlist columns of the capacity table are not rendered,
            // and the two thresholds stay visible but disabled — they
            // describe a mechanism that is not running, and their stored
            // values are untouched.
            'waitlist_enabled' => $this->isWaitlistEnabled(),
            // The pair the levels are actually computed with — the stored
            // one, or the defaults when the stored pair is unusable — so the
            // fields never show a number the page does not apply.
            'level_thresholds' => $this->slotService->levelThresholds(),
            'level_thresholds_unusable' => $this->slotService->storedThresholdsAreUnusable(),
            'threshold_min_percent' => SlotMath::THRESHOLD_MIN_PERCENT,
            'threshold_max_percent' => SlotMath::THRESHOLD_MAX_PERCENT,
            'default_capacity' => SlotService::DEFAULT_CAPACITY,
            'capacity_rows' => $this->capacityRows(),

            // « Priorités »: the three connected lists, in the order the
            // engine reads them (Service\RecommendationService).
            'priority_zones' => $this->priorityZones($this->priorityOrderService->current()),
            'priority_order_unusable' => $this->priorityOrderService->storedOrderIsUnusable(),
            'priority_rank_only_message' => self::MESSAGE_RANK_ONLY_IN_ALWAYS,
        ];
    }

    /**
     * The capacity grid, one row per slot — « Louveteaux · 1re année », the
     * name the recommendation gives it too — with what is stored for it
     * (null: « pas de limite »).
     *
     * @return array<int, array{age_branch_id: int, year_in_branch: int, label: string, capacity: ?int}>
     */
    private function capacityRows(): array
    {
        $capacities = $this->slotCapacityRepository->findAllAsMap();
        $rows = [];
        foreach ($this->ageBracketRepository->findAllOrdered() as $bracket) {
            for ($yearInBranch = 1; $yearInBranch <= $bracket->durationYears; $yearInBranch++) {
                $rows[] = [
                    'age_branch_id' => $bracket->ageBranchId,
                    'year_in_branch' => $yearInBranch,
                    'label' => RecommendationService::slotLabel($bracket, $yearInBranch),
                    'capacity' => $capacities[$bracket->ageBranchId][$yearInBranch] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * The three zones of the « Priorités » box, with the mock-up's titles,
     * help lines and empty sentences, each holding its criteria in order.
     *
     * @return array<int, array{
     *   key: string, title: string, help: string, empty_label: string,
     *   items: array<int, array{id: string, label: string, help: string, can_always: bool}>
     * }>
     */
    private function priorityZones(PriorityOrder $order): array
    {
        $zones = [
            PriorityOrder::ZONE_ALWAYS => [
                'title' => 'Toujours Oui',
                'help' => 'Ces demandes passent en premier, sont toujours recommandées « Oui » et peuvent dépasser '
                    . 'la capacité.',
                'empty_label' => 'Aucune règle : toutes les demandes sont classées.',
            ],
            PriorityOrder::ZONE_RANK => [
                'title' => 'Classement',
                'help' => 'Les autres demandes sont classées par ces critères, de haut en bas, puis acceptées dans '
                    . 'cet ordre jusqu\'au nombre de places.',
                'empty_label' => 'Aucun critère : les demandes sont acceptées dans l\'ordre de dépôt.',
            ],
            PriorityOrder::ZONE_OFF => [
                'title' => 'Désactivés',
                'help' => '',
                'empty_label' => 'Glissez un critère ici pour le désactiver.',
            ],
        ];

        $result = [];
        foreach ($order->toZones() as $zone => $criteria) {
            $items = [];
            foreach ($criteria as $criterion) {
                $items[] = [
                    'id' => $criterion,
                    'label' => PriorityOrder::CRITERIA[$criterion]['label'],
                    'help' => PriorityOrder::CRITERIA[$criterion]['help'],
                    'can_always' => PriorityOrder::CRITERIA[$criterion]['can_always'],
                ];
            }
            $result[] = $zones[$zone] + ['key' => $zone, 'items' => $items];
        }

        return $result;
    }

    /**
     * One date picker of the recurring schedule: the stored "MM-DD" turned
     * into the real date the scheduled task will next act on
     * (Service\RecurringMonthDay::nextTransition()) — that is what a
     * native date picker can show — plus the two cases the page has to
     * spell out: a date that has just passed and is still to be applied
     * (`pending`), and a 29th of February stored through the generic
     * settings page (`leap_day`), which this form refuses to save back.
     * Trimmed exactly as the handlers trim it.
     *
     * @return array{date: ?\DateTimeImmutable, pending: bool, leap_day: bool}
     */
    private function scheduleField(string $scheduleKey, string $appliedOnKey): array
    {
        $monthDay = trim((string) $this->settingService->get($scheduleKey, 'registration', ''));
        $now = new \DateTimeImmutable();
        $date = RecurringMonthDay::nextTransition(
            $monthDay,
            $now,
            trim((string) $this->settingService->get($appliedOnKey, 'registration', '')),
            OpenRegistrationHandler::catchUpDays($this->settingService)
        );

        return [
            'date' => $date,
            'pending' => $date !== null && $date->format('Y-m-d') < $now->format('Y-m-d'),
            'leap_day' => $monthDay === '02-29',
        ];
    }

    private function isFormOpen(): bool
    {
        return $this->settingService->get('registration_form_open', 'registration', '0') === '1';
    }

    private function isWaitlistEnabled(): bool
    {
        return $this->slotService->waitlistEnabled();
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
    private function buildFilteredRequestRows(
        int $selectedYearId,
        array $selectedYear,
        ?string $statusFilter,
        ?string $search
    ): array
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
