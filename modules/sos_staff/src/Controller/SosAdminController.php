<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Service\DateInput;
use Core\Service\IntegerInput;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\SosStaff\Api\ProviderException;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use Twig\Environment;

/**
 * /admin/sos — the duty-roster pages (module spec §2), in three sub-pages
 * (issue #1026): the dashboard (where the calls go now, and what is
 * planned), the planning, and the settings. role_min admin (chief d'unité)
 * — day-to-day planning, distinct from /config/sos (superadmin, holds the
 * API credentials).
 */
class SosAdminController extends AbstractController
{
    /**
     * The sub-pages, in the order the rail shows them. The first is the
     * page /admin/sos itself opens on.
     */
    private const PAGE_DASHBOARD = '/admin/sos';
    private const PAGE_PLANNING = '/admin/sos/planning';
    private const PAGE_SETTINGS = '/admin/sos/reglages';

    public function __construct(
        protected Environment $twig,
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private OnCallService $onCallService,
        private RedirectService $redirectService,
        private SectionService $sectionService,
        private SchedulerService $schedulerService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        private ?CalendarEventLookupInterface $calendarEvents = null
    ) {
    }

    /**
     * Tableau de bord — where the calls go RIGHT NOW (as read from the
     * provider), the one correction that can be made from here, and what is
     * planned to change.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        // The planning used to live on this very page: a bookmark or a
        // notification link carrying a month still lands on that month.
        $requestedMonth = $request->getQuery('month');
        if (is_string($requestedMonth) && $requestedMonth !== '') {
            return $this->redirect(self::PAGE_PLANNING . '?month=' . rawurlencode($requestedMonth));
        }

        if ($this->providerConfigService->getActiveProvider() === null) {
            return $this->renderNotConfigured();
        }

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        // The list follows the CURRENT month: this page has no month to
        // browse, and what is about to change is what it is for.
        $year = (int) date('Y');
        $month = (int) date('n');
        $transitionsPage = max(1, (int) $request->getQuery('transitions_page', 1));
        $transitions = $this->buildPlannedTransitions($year, $month, $effectiveYear->id, $transitionsPage);

        $page = [
            'sos_page' => self::PAGE_DASHBOARD,
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'planned_transitions' => $transitions['entries'],
            'planned_transitions_page' => $transitions['page'],
            'planned_transitions_total_pages' => $transitions['total_pages'],
        ];

        return $this->render(
            '@sos_staff/dashboard.html.twig',
            $page + $this->redirectBlockContext($this->buildRedirectStatus($effectiveYear->id))
        );
    }

    /**
     * Planning — the month of duties, on a phone as a list of days plus one
     * full-screen sheet, on a computer as the grid.
     *
     * @param array<string, string> $params
     */
    public function planning(Request $request, array $params): Response
    {
        if ($this->providerConfigService->getActiveProvider() === null) {
            return $this->renderNotConfigured();
        }

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $staffOptions = $this->settingsService->getStaffOptions($effectiveYear->id);
        $orderedStaffMemberIds = array_column($staffOptions, 'member_id');

        [$year, $month] = $this->resolveRequestedMonth($request->getQuery('month'));
        $grid = $this->onCallService->getMonthGrid($year, $month);

        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear = $month === 12 ? $year + 1 : $year;

        $sectionActivity = $this->buildSectionActivity($year, $month, $role);
        $defaultMemberId = $this->settingsService->resolveDefaultNumberMemberId($effectiveYear->id);

        $today = (new \DateTimeImmutable())->format('Y-m-d');

        return $this->render('@sos_staff/planning.html.twig', [
            'sos_page' => self::PAGE_PLANNING,
            'staff_options' => $staffOptions,
            'default_number_label' => $this->labelFromOptions($staffOptions, $defaultMemberId),
            'year' => $year,
            'month' => $month,
            'today' => $today,
            'month_label' => $this->monthLabel($year, $month),
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'prev_month_param' => sprintf('%04d-%02d', $prevYear, $prevMonth),
            'next_month_param' => sprintf('%04d-%02d', $nextYear, $nextMonth),
            'days' => $grid['days'],
            'states' => $grid['states'],
            'section_activity' => $sectionActivity,
            // The phone layout only (the desktop .sos-grid still renders
            // `days` whole, and is not concerned by any of this).
            'mobile_days' => $this->buildMobileDays(
                $grid,
                $sectionActivity,
                $this->onCallService->resolveTargetsForMonth($year, $month, $orderedStaffMemberIds),
                $staffOptions,
                $today
            ),
            'ordered_staff_member_ids' => array_map('intval', $orderedStaffMemberIds),
        ]);
    }

    /**
     * Réglages — the three settings that used to sit at the bottom of the
     * planning page, unchanged.
     *
     * @param array<string, string> $params
     */
    public function settings(Request $request, array $params): Response
    {
        if ($this->providerConfigService->getActiveProvider() === null) {
            return $this->renderNotConfigured();
        }

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $staffOptions = $this->settingsService->getStaffOptions($effectiveYear->id);
        $orderedStaffMemberIds = array_column($staffOptions, 'member_id');
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        return $this->render('@sos_staff/settings.html.twig', [
            'sos_page' => self::PAGE_SETTINGS,
            'staff_options' => $staffOptions,
            'default_number_selection_member_id' => $this->settingsService
                ->resolveDefaultNumberMemberId($effectiveYear->id),
            // §4b: the warning beside the default-number picker is shown
            // only when saving would really change who the SOS line rings
            // — exactly the condition applyImmediatelyIfTodayUsesDefault()
            // acts on. A permanent warning that is wrong half the time
            // ends up ignored.
            'today_uses_default_number' => $this->onCallService
                ->resolveTargetForDate($today, $orderedStaffMemberIds) === null,
            'transition_hour' => $this->settingsService->getTransitionHour(),
            'email_notifications_enabled' => $this->settingsService->isEmailNotificationsEnabled(),
        ]);
    }

    /**
     * « Réactiver / Resynchroniser la redirection » (issue #1026): puts the
     * provider's real state back on the destination the planning expects.
     *
     * **Nothing the browser sends decides anything.** The destination is
     * worked out here, now, by the same rules the scheduled transitions
     * use (OnCallService::resolveExpectedTargetAt() and
     * RedirectService::resolveNumber()); the request body carries the CSRF
     * token and nothing else. Neither the month the visitor was browsing
     * nor a number they could have typed into the request can steer the
     * emergency line.
     *
     * The change itself is RedirectService::apply() — the check, the
     * provider call, the read-back, the journal, the handover
     * notifications — and the state is then read from the provider once
     * more here: an acknowledgement of the request is not a success, only a
     * redirection that reads back as the expected one is. The planning and
     * the transitions already scheduled are not touched.
     *
     * @param array<string, string> $params
     */
    public function resynchronizeRedirect(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        if ($this->providerConfigService->getActiveProvider() === null) {
            return $this->json(['success' => false, 'error' => "La téléphonie n'est pas encore configurée."], 409);
        }

        $role = Role::fromString(AuthSession::getRole());
        $scoutYearId = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role)->id;

        $status = $this->buildRedirectStatus($scoutYearId);
        if ($status['live_state']['error'] !== null) {
            return $this->json([
                'success' => false,
                'error' => "Impossible de lire l'état de la redirection chez le fournisseur : "
                    . $status['live_state']['error'],
            ], 409);
        }
        if ($status['expected_number'] === null) {
            return $this->json([
                'success' => false,
                'error' => "Aucun destinataire valide : ni garde aujourd'hui, ni numéro par défaut utilisable.",
            ], 409);
        }
        if ($status['action'] === null) {
            // Someone else — or the scheduler — got there first. It was read
            // from the provider just now, so saying so is true.
            return $this->json([
                'success' => true,
                'message' => 'La redirection est déjà conforme.',
                'html' => $this->renderRedirectBlock($status),
            ]);
        }

        $this->journalService->log(
            'sos_staff',
            'redirect_manual_requested',
            'info',
            $status['action'] === 'reactivate'
                ? 'Réactivation manuelle de la redirection demandée'
                : 'Resynchronisation manuelle de la redirection demandée',
            ['member_id' => $status['expected_member_id']],
            AuthSession::getUserAccountId()
        );

        try {
            // The member the line is leaving, if it was on one, is told the
            // same way a scheduled handover tells them.
            $this->redirectService->apply(
                $status['expected_member_id'],
                $status['live_state']['active'] ? $status['live_state']['member_id'] : null,
                $scoutYearId
            );
        } catch (SosException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 502);
        }

        $after = $this->buildRedirectStatus($scoutYearId);
        if ($after['live_state']['error'] !== null || $after['action'] !== null) {
            return $this->json([
                'success' => false,
                'error' => "La redirection n'a pas pu être vérifiée chez le fournisseur — réessayez.",
            ], 502);
        }

        return $this->json([
            'success' => true,
            'message' => $status['action'] === 'reactivate'
                ? 'Redirection réactivée et vérifiée.'
                : 'Redirection resynchronisée et vérifiée.',
            'html' => $this->renderRedirectBlock($after),
        ]);
    }

    /**
     * Without a provider nothing on these pages has any effect — a planning
     * would route calls to nowhere (issue #750) — so each sub-page says so
     * once, with a link for whoever can configure it, and shows nothing else.
     */
    private function renderNotConfigured(): Response
    {
        $role = Role::fromString(AuthSession::getRole());

        return $this->render('@sos_staff/not_configured.html.twig', [
            'can_configure' => $role->hasAccess(Role::SUPERADMIN),
        ]);
    }

    /**
     * What the dashboard's « Redirection actuelle » block shows and offers.
     *
     * The provider's real state is compared with where the planning says
     * the calls should be going NOW (see
     * OnCallService::resolveExpectedTargetAt() for why that is not simply
     * today's target). The correction is offered only when both are known
     * and they differ; an unreadable provider or the lack of any valid
     * destination is information, never a misalignment to «fix».
     *
     * @return array{
     *   live_state: array{active: bool, number: ?string, label: ?string, member_id: ?int, error: ?string},
     *   expected_member_id: ?int,
     *   expected_number: ?string,
     *   expected_label: ?string,
     *   action: ?string
     * }
     */
    private function buildRedirectStatus(int $scoutYearId): array
    {
        $live = $this->resolveLiveState($scoutYearId);

        $staffOptions = $this->settingsService->getStaffOptions($scoutYearId);
        $expectedMemberId = $this->onCallService->resolveExpectedTargetAt(
            new \DateTimeImmutable(),
            array_column($staffOptions, 'member_id')
        )['member_id'];
        $expectedNumber = $this->redirectService->resolveNumber($expectedMemberId, $scoutYearId);

        $action = null;
        if ($live['error'] === null && $expectedNumber !== null) {
            if (!$live['active']) {
                $action = 'reactivate';
            } elseif ($live['number'] !== $expectedNumber) {
                $action = 'resync';
            }
        }

        $expectedLabel = null;
        foreach ($staffOptions as $option) {
            if ($option['mobile'] === $expectedNumber) {
                $expectedLabel = $option['label'];
                break;
            }
        }

        return [
            'live_state' => $live,
            'expected_member_id' => $expectedMemberId,
            'expected_number' => $expectedNumber,
            'expected_label' => $expectedLabel,
            'action' => $action,
        ];
    }

    /**
     * @param array{
     *   live_state: array{active: bool, number: ?string, label: ?string, member_id: ?int, error: ?string},
     *   expected_member_id: ?int,
     *   expected_number: ?string,
     *   expected_label: ?string,
     *   action: ?string
     * } $status
     * @return array<string, mixed>
     */
    private function redirectBlockContext(array $status): array
    {
        return [
            'sos_number' => $this->providerConfigService->getSosNumber(),
            'live_state' => $status['live_state'],
            'redirect_action' => $status['action'],
            'redirect_expected_number' => $status['expected_number'],
            'redirect_expected_label' => $status['expected_label'],
        ];
    }

    /**
     * The block alone, for the answer to the correction — the same partial
     * the dashboard includes, so the two can never drift.
     *
     * @param array{
     *   live_state: array{active: bool, number: ?string, label: ?string, member_id: ?int, error: ?string},
     *   expected_member_id: ?int,
     *   expected_number: ?string,
     *   expected_label: ?string,
     *   action: ?string
     * } $status
     */
    private function renderRedirectBlock(array $status): string
    {
        return $this->renderToString(
            '@sos_staff/partials/redirect_status.html.twig',
            $this->redirectBlockContext($status)
        );
    }

    /**
     * The phone layout's day list (spec §3 "A — the month, read-only").
     *
     * Everything the rows show is resolved here rather than in the
     * template: who actually receives the calls that day (the service's
     * §2.6 roster-order rule, never recomputed from the states grid), how
     * many people are marked so the page can flag a day where the extra
     * marks change nothing, the day written out in full for the edit
     * sheet's title, and the sections' activity as « Section — titre »
     * lines for the day sheet.
     *
     * **Range**: the CURRENT month starts at today. Past days of the
     * running month are not editable in practice and pushing the useful
     * part of the list off the first two screens is the whole defect this
     * screen exists to fix. Any other month is shown whole.
     *
     * @param array{
     *   days: array<int, array{date: string, day_number: int, day_name: string, is_today: bool, is_weekend: bool}>,
     *   states: array<string, array<int, string>>
     * } $grid
     * @param array<int, array{
     *   section_id: int, label: string, color: ?string, events_by_day: array<string, string[]>
     * }> $sectionActivity
     * @param array<string, array{member_id: ?int, oncall_count: int}> $targets
     * @param array<int, array{member_id: int, label: string, mobile: string}> $staffOptions
     * @return array<int, array{
     *   date: string, day_number: int, day_name: string, date_label: string,
     *   is_today: bool, is_weekend: bool, activity: string[],
     *   target_member_id: ?int, target_label: ?string, oncall_count: int
     * }>
     */
    private function buildMobileDays(
        array $grid,
        array $sectionActivity,
        array $targets,
        array $staffOptions,
        string $today
    ): array {
        $rows = [];
        foreach ($grid['days'] as $day) {
            // « Section — titre »: the day sheet lists them one per line,
            // and a title alone (« Réunion ») does not say whose (#750).
            $activity = [];
            foreach ($sectionActivity as $column) {
                foreach ($column['events_by_day'][$day['date']] ?? [] as $title) {
                    $activity[] = $column['label'] . ' — ' . $title;
                }
            }

            $target = $targets[$day['date']] ?? ['member_id' => null, 'oncall_count' => 0];

            $rows[] = [
                'date' => $day['date'],
                'day_number' => $day['day_number'],
                'day_name' => $day['day_name'],
                'date_label' => $this->longDateLabel($day['date']),
                'is_today' => $day['is_today'],
                'is_weekend' => $day['is_weekend'],
                'activity' => array_values(array_unique($activity)),
                'target_member_id' => $target['member_id'],
                'target_label' => $this->labelFromOptions($staffOptions, $target['member_id']),
                'oncall_count' => $target['oncall_count'],
            ];
        }

        // Only the running month is trimmed, and only from its start: a
        // month reached by navigation is shown whole, past or future.
        $firstShown = $rows[0]['date'] ?? null;
        $lastShown = $rows[count($rows) - 1]['date'] ?? null;
        if ($firstShown !== null && $lastShown !== null && $firstShown <= $today && $today <= $lastShown) {
            $rows = array_values(array_filter($rows, static fn(array $row) => $row['date'] >= $today));
        }

        return $rows;
    }

    /**
     * @param array<int, array{member_id: int, label: string, mobile: string}> $staffOptions
     */
    private function labelFromOptions(array $staffOptions, ?int $memberId): ?string
    {
        if ($memberId === null) {
            return null;
        }
        foreach ($staffOptions as $option) {
            if ((int) $option['member_id'] === $memberId) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * « lundi 15 juin 2026 » — the sheet's title says which day is being
     * edited in full, since the row it was opened from is no longer on
     * screen. The `french_date` Twig filter stops at "15 juin 2026"; the
     * weekday is the half that matters when planning a duty.
     */
    private function longDateLabel(string $date): string
    {
        $dayNames = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
        $months = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
        ];

        $day = DateInput::iso($date);
        if ($day === null) {
            return $date;
        }

        return sprintf(
            '%s %d %s %s',
            $dayNames[(int) $day->format('N') - 1],
            (int) $day->format('j'),
            $months[(int) $day->format('n')],
            $day->format('Y')
        );
    }

    /**
     * AJAX pagination for the "Redirections planifiées" list (GET, no CSRF
     * needed — read-only) so paging doesn't reload the whole admin page.
     * Renders the same partial as index(), so markup never drifts between
     * the initial page load and paged AJAX requests.
     *
     * @param array<string, string> $params
     */
    public function plannedTransitions(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        [$year, $month] = $this->resolveRequestedMonth($request->getQuery('month'));
        $page = max(1, (int) $request->getQuery('transitions_page', 1));
        $result = $this->buildPlannedTransitions($year, $month, $effectiveYear->id, $page);

        return $this->render('@sos_staff/partials/planned_transitions.html.twig', [
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'planned_transitions' => $result['entries'],
            'planned_transitions_page' => $result['page'],
            'planned_transitions_total_pages' => $result['total_pages'],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateDefaultNumber(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        if (empty($data['member_id'])) {
            return $this->json(['success' => false, 'error' => 'Membre requis.'], 400);
        }

        // Only a member the picker actually offers — a Staff d'U member with
        // a known mobile for the effective year. getDefaultNumber() resolves
        // whatever is stored here into the live redirect target of the public
        // SOS line, so an unvalidated id would point the unit's emergency
        // number at any member's personal phone.
        $memberId = (int) $data['member_id'];
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $isStaffOption = false;
        foreach ($this->settingsService->getStaffOptions($effectiveYear->id) as $option) {
            if ($option['member_id'] === $memberId) {
                $isStaffOption = true;
                break;
            }
        }
        if (!$isStaffOption) {
            return $this->json(['success' => false, 'error' => 'Membre invalide.'], 400);
        }

        $this->settingsService->setDefaultNumberFromMember($memberId);

        $this->journalService->log(
            'sos_staff',
            'default_number_updated',
            'info',
            'Numéro par défaut SOS modifié',
            [],
            AuthSession::getUserAccountId()
        );

        $this->applyImmediatelyIfTodayUsesDefault();

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateSettings(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            if (isset($data['transition_hour'])) {
                $this->settingsService->setTransitionHour((string) $data['transition_hour']);
            }
            if (array_key_exists('email_notifications_enabled', $data)) {
                $this->settingsService->setEmailNotificationsEnabled((bool) $data['email_notifications_enabled']);
            }
        } catch (SosException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'sos_staff',
            'settings_updated',
            'info',
            'Réglages SOS modifiés',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * Save the complete month state (module spec §2.6), recompute
     * transitions (§3), purge >1 year old data (§6), and apply today's
     * transition immediately if it's already due (§3 "application
     * immédiate"). The calendar needs no syncing here: duty periods are
     * virtual events computed live from the saved assignments
     * (Calendar\SosVirtualEventProvider, §7.6).
     *
     * @param array<string, string> $params
     */
    public function saveOnCall(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $year = (int) ($data['year'] ?? 0);
        $month = (int) ($data['month'] ?? 0);
        $cells = is_array($data['cells'] ?? null) ? $data['cells'] : [];

        // The month is checked by composing it and asking whether that is
        // a date, rather than by asserting a range nobody agreed on. A
        // year of 99999999999 formats into something DateInput refuses,
        // where OnCallService::saveMonth() used to hand it straight to
        // `new DateTimeImmutable()` and take an uncaught exception.
        $firstOfMonth = DateInput::iso(sprintf('%04d-%02d-01', $year, $month));
        if ($year < 1 || $month < 1 || $month > 12 || $firstOfMonth === null) {
            return $this->json(['success' => false, 'error' => 'Mois invalide.'], 400);
        }
        $lastOfMonth = $firstOfMonth->modify('last day of this month')->format('Y-m-d');

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $staffOptions = $this->settingsService->getStaffOptions($effectiveYear->id);
        $orderedStaffMemberIds = array_column($staffOptions, 'member_id');

        // Every field of a cell is checked against something this request
        // already knows, not merely cast:
        //
        //  - the member against the roster that is being displayed. That
        //    is a stricter check than "is a number", and it is the one
        //    that matters: `member_id` is a foreign key, so an id that is
        //    merely well-formed still reaches MySQL as an integrity
        //    violation and an uncaught PDOException (SECURITY.md § 35).
        //  - the date against the month being saved. saveMonth() replaces
        //    the whole month in one transaction — it DELETEs the range,
        //    then inserts these rows — so a row dated outside that range
        //    is written once and never cleared by any later save.
        //
        // A cell failing either is dropped, matching how this loop
        // already treats an unknown state: the grid posts the month it
        // rendered, and anything else was not in it.
        $validCells = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !isset($cell['member_id'], $cell['date'], $cell['state'])) {
                continue;
            }
            if (!in_array(
                (string) $cell['state'],
                [OnCallAssignment::STATE_ONCALL, OnCallAssignment::STATE_UNAVAILABLE],
                true
            )) {
                continue;
            }

            $memberId = IntegerInput::unsigned($cell['member_id']);
            if ($memberId === null || !in_array($memberId, array_map('intval', $orderedStaffMemberIds), true)) {
                continue;
            }

            $date = DateInput::isoStringOrNull(is_string($cell['date']) ? $cell['date'] : null);
            if ($date === null || $date < $firstOfMonth->format('Y-m-d') || $date > $lastOfMonth) {
                continue;
            }

            $validCells[] = ['member_id' => $memberId, 'date' => $date, 'state' => (string) $cell['state']];
        }

        $result = $this->onCallService->saveMonth(
            $year,
            $month,
            $validCells,
            $orderedStaffMemberIds,
            $effectiveYear->id
        );

        $this->onCallService->cleanupOlderThanOneYear();

        if ($result['today_due_now']) {
            try {
                $this->redirectService->apply(
                    $result['today_transition']['member_id'],
                    $result['today_transition']['previous_member_id'],
                    $effectiveYear->id
                );
            } catch (SosException $e) {
                // Already journaled and alerted inside apply() — the grid
                // save itself still succeeded, so this isn't a failure
                // response, just a note for the client.
            }
        }

        $this->journalService->log(
            'sos_staff',
            'oncall_saved',
            'info',
            'Planning de garde SOS mis à jour',
            ['year' => $year, 'month' => $month],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'transitions' => count($result['transitions'])]);
    }

    /**
     * @return array{active: bool, number: ?string, label: ?string, member_id: ?int, error: ?string}
     */
    private function resolveLiveState(int $scoutYearId): array
    {
        $provider = $this->providerConfigService->getActiveProvider();
        if ($provider === null) {
            return ['active' => false, 'number' => null, 'label' => null, 'member_id' => null, 'error' => 'Aucun '
                . 'fournisseur de téléphonie actif configuré.'];
        }

        try {
            $state = $provider->readForwardingState();
        } catch (ProviderException $e) {
            return [
                'active' => false, 'number' => null, 'label' => null, 'member_id' => null,
                'error' => $e->getMessage(),
            ];
        }

        $label = null;
        $memberId = null;
        if ($state->number !== null) {
            foreach ($this->settingsService->getStaffOptions($scoutYearId) as $option) {
                if ($option['mobile'] === $state->number) {
                    $label = $option['label'];
                    $memberId = (int) $option['member_id'];
                    break;
                }
            }
        }

        return [
            'active' => $state->active, 'number' => $state->number, 'label' => $label, 'member_id' => $memberId,
            'error' => null,
        ];
    }

    /**
     * Left zone (module spec §2.6): one column per non-excluded section,
     * days marked when that section's calendar has an event. No-ops (no
     * columns) when the calendar module is disabled. The per-day activity
     * comes from the calendar module's read Api
     * (Modules\Calendar\Api\CalendarEventLookupInterface, §7.5) — one call
     * for the whole month, every section; a section the Api doesn't
     * mention simply has no calendar (or none this role may see) and
     * renders as an empty column.
     *
     * @return array<int, array{section_id: int, label: string, color: ?string, events_by_day: array<string, string[]>}>
     */
    private function buildSectionActivity(int $year, int $month, Role $role): array
    {
        if ($this->calendarEvents === null) {
            return [];
        }

        $activityBySectionId = $this->calendarEvents->sectionActivityForMonth($year, $month, $role);

        $excludedIds = $this->settingsService->getExcludedSectionIds();
        $sections = array_values(array_filter(
            $this->sectionService->getAllWithBranches(),
            fn(array $s) => !in_array($s['id'], $excludedIds, true)
        ));

        $columns = [];
        foreach ($sections as $section) {
            $activity = $activityBySectionId[$section['id']] ?? null;

            $columns[] = [
                'section_id' => $section['id'],
                'label' => $section['name'] ?? $section['desk_code'],
                'color' => $activity?->color,
                'events_by_day' => $activity->eventTitlesByDay ?? [],
            ];
        }

        return $columns;
    }

    /**
     * Every transition from the first of the given month onward (the
     * month's own, already-run ones included, for context), sorted
     * future-first (descending run_at) for display; the single nearest
     * upcoming transition is flagged is_next for highlighting. Capped at 10
     * entries per page.
     *
     * @return array{
     *     entries: array<int, array{date: string, run_at: string, label: string, status: string, is_next: bool}>,
     *     page: int,
     *     total_pages: int
     * }
     */
    private function buildPlannedTransitions(int $year, int $month, int $scoutYearId, int $page = 1): array
    {
        // From the first of the month ON, not that month alone: the list is
        // about what is still to come, and a handover scheduled for the 1st
        // must show on the 31st (it is the next one, a few hours away).
        $from = sprintf('%04d-%02d-01', $year, $month);
        $rows = array_values(array_filter(
            $this->schedulerService->findAllForTask(OnCallService::MODULE_ID, OnCallService::TASK_KEY, 500),
            fn(array $row) => (string) $row['reference'] >= $from && $row['status'] !== 'canceled'
        ));
        usort($rows, fn(array $a, array $b) => $a['run_at'] <=> $b['run_at']);

        $now = new \DateTimeImmutable();
        $latestPastIndex = null;
        $nextFutureIndex = null;
        $entries = [];
        foreach ($rows as $index => $row) {
            $payload = json_decode((string) $row['payload'], true);
            $memberId = is_array($payload) ? ($payload['member_id'] ?? null) : null;
            $label = $memberId !== null
                ? ($this->settingsService->labelForMember((int) $memberId, $scoutYearId) ?? 'Membre')
                : 'Numéro par défaut';

            $runAt = DateInput::requireFromStorage((string) $row['run_at'], 'run_at');
            $status = $runAt > $now ? 'à venir' : 'exécuté';
            if ($runAt <= $now) {
                $latestPastIndex = $index;
            } elseif ($nextFutureIndex === null) {
                $nextFutureIndex = $index;
            }

            $entries[] = [
                'date' => (string) $row['reference'],
                'run_at' => $runAt->format('d/m/Y H:i'),
                'label' => $label,
                'status' => $status,
                'is_next' => false,
            ];
        }

        if ($latestPastIndex !== null) {
            $entries[$latestPastIndex]['status'] = 'actif';
        }
        if ($nextFutureIndex !== null) {
            $entries[$nextFutureIndex]['is_next'] = true;
        }

        // Display order: future on top, past on bottom.
        $entries = array_reverse($entries);

        $perPage = 10;
        $totalPages = max(1, (int) ceil(count($entries) / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'entries' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'total_pages' => $totalPages,
        ];
    }

    private function applyImmediatelyIfTodayUsesDefault(): void
    {
        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $staffOptions = $this->settingsService->getStaffOptions($effectiveYear->id);
        $orderedStaffMemberIds = array_column($staffOptions, 'member_id');

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $todayTarget = $this->onCallService->resolveTargetForDate($today, $orderedStaffMemberIds);

        if ($todayTarget === null) {
            try {
                $this->redirectService->apply(null, null, $effectiveYear->id);
            } catch (SosException $e) {
                // Already journaled and alerted inside apply().
            }
        }
    }

    /**
     * @return array<string, mixed>|Response an array on success, or an
     *                                       error Response to return as-is
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        return $data;
    }

    /**
     * @return array{0: int, 1: int} [year, month]
     */
    private function resolveRequestedMonth(mixed $requested): array
    {
        if (is_string($requested) && preg_match('/^(\d{4})-(\d{2})$/', $requested, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            // The year is bounded as well as the month: `\d{4}` matches
            // "0000", and a month grid is built from a real calendar month
            // (DateInput::firstOfMonth()), which year zero is not. Falling
            // back to today is what this already does for month 13.
            if ($year >= 1000 && $month >= 1 && $month <= 12) {
                return [$year, $month];
            }
        }
        return [(int) date('Y'), (int) date('n')];
    }

    private function monthLabel(int $year, int $month): string
    {
        $months = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];
        return "{$months[$month]} {$year}";
    }
}
