<?php

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
use Modules\Calendar\Service\CalendarService;
use Modules\SosStaff\Provider\ProviderException;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Service\CalendarSyncService;
use Modules\SosStaff\Service\OnCallService;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use Twig\Environment;

/**
 * /admin/sos — the duty-roster planning page (module spec §2). role_min
 * admin (chief d'unité) — day-to-day planning, distinct from /config/sos
 * (superadmin, holds the API credentials).
 */
class SosAdminController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ProviderConfigService $providerConfigService,
        private SosSettingsService $settingsService,
        private OnCallService $onCallService,
        private RedirectService $redirectService,
        private CalendarSyncService $calendarSyncService,
        private SectionService $sectionService,
        private SchedulerService $schedulerService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        private ?CalendarService $calendarService = null
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
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

        $transitionsPage = max(1, (int) $request->getQuery('transitions_page', 1));
        $transitions = $this->buildPlannedTransitions($year, $month, $effectiveYear->id, $transitionsPage);

        return $this->render('@sos_staff/admin.html.twig', [
            'sos_number' => $this->providerConfigService->getSosNumber(),
            'live_state' => $this->resolveLiveState($effectiveYear->id),
            'staff_options' => $staffOptions,
            'default_number_selection_member_id' => $this->settingsService->resolveDefaultNumberMemberId($effectiveYear->id),
            'transition_hour' => $this->settingsService->getTransitionHour(),
            'email_notifications_enabled' => $this->settingsService->isEmailNotificationsEnabled(),
            'year' => $year,
            'month' => $month,
            'month_label' => $this->monthLabel($year, $month),
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'prev_month_param' => sprintf('%04d-%02d', $prevYear, $prevMonth),
            'next_month_param' => sprintf('%04d-%02d', $nextYear, $nextMonth),
            'days' => $grid['days'],
            'states' => $grid['states'],
            'section_activity' => $this->buildSectionActivity($year, $month),
            'planned_transitions' => $transitions['entries'],
            'planned_transitions_page' => $transitions['page'],
            'planned_transitions_total_pages' => $transitions['total_pages'],
        ]);
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
        $this->settingsService->setDefaultNumberFromMember((int) $data['member_id']);

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
     * transitions (§3), sync the calendar (§5), purge >1 year old data
     * (§6), and apply today's transition immediately if it's already due
     * (§3 "application immédiate").
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
        if ($year < 1 || $month < 1 || $month > 12) {
            return $this->json(['success' => false, 'error' => 'Mois invalide.'], 400);
        }

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        $staffOptions = $this->settingsService->getStaffOptions($effectiveYear->id);
        $orderedStaffMemberIds = array_column($staffOptions, 'member_id');

        $validCells = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !isset($cell['member_id'], $cell['date'], $cell['state'])) {
                continue;
            }
            if (!in_array((string) $cell['state'], [OnCallAssignment::STATE_ONCALL, OnCallAssignment::STATE_UNAVAILABLE], true)) {
                continue;
            }
            $validCells[] = ['member_id' => (int) $cell['member_id'], 'date' => (string) $cell['date'], 'state' => (string) $cell['state']];
        }

        $result = $this->onCallService->saveMonth($year, $month, $validCells, $orderedStaffMemberIds, $effectiveYear->id);

        $this->calendarSyncService->resync($effectiveYear->id);
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
     * @return array{active: bool, number: ?string, label: ?string, error: ?string}
     */
    private function resolveLiveState(int $scoutYearId): array
    {
        $provider = $this->providerConfigService->getActiveProvider();
        if ($provider === null) {
            return ['active' => false, 'number' => null, 'label' => null, 'error' => 'Aucun fournisseur de téléphonie actif configuré.'];
        }

        try {
            $state = $provider->readForwardingState();
        } catch (ProviderException $e) {
            return ['active' => false, 'number' => null, 'label' => null, 'error' => $e->getMessage()];
        }

        $label = null;
        if ($state->number !== null) {
            foreach ($this->settingsService->getStaffOptions($scoutYearId) as $option) {
                if ($option['mobile'] === $state->number) {
                    $label = $option['label'];
                    break;
                }
            }
        }

        return ['active' => $state->active, 'number' => $state->number, 'label' => $label, 'error' => null];
    }

    /**
     * Left zone (module spec §2.6): one column per non-excluded section,
     * days marked when that section's calendar has an event. No-ops (no
     * columns) when the calendar module is disabled.
     *
     * @return array<int, array{section_id: int, label: string, color: ?string, events_by_day: array<string, string[]>}>
     */
    private function buildSectionActivity(int $year, int $month): array
    {
        if ($this->calendarService === null) {
            return [];
        }

        $this->calendarService->ensureSectionCalendars();
        $excludedIds = $this->settingsService->getExcludedSectionIds();
        $sections = array_values(array_filter(
            $this->sectionService->getAllWithBranches(),
            fn(array $s) => !in_array($s['id'], $excludedIds, true)
        ));

        $calendarBySectionId = [];
        foreach ($this->calendarService->getSectionCalendars() as $calendar) {
            if ($calendar->sectionId !== null) {
                $calendarBySectionId[$calendar->sectionId] = $calendar;
            }
        }
        $colors = $this->calendarService->colorsByCalendarId();

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = (new \DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

        $columns = [];
        foreach ($sections as $section) {
            $calendar = $calendarBySectionId[$section['id']] ?? null;
            $eventsByDay = [];

            if ($calendar !== null) {
                foreach ($this->calendarService->getEventsForMonth([$calendar->id], $year, $month) as $event) {
                    $start = max($event->startDate, $monthStart);
                    $end = min($event->endDate ?? $event->startDate, $monthEnd);
                    $cursor = new \DateTimeImmutable($start);
                    $endDate = new \DateTimeImmutable($end);
                    while ($cursor <= $endDate) {
                        $eventsByDay[$cursor->format('Y-m-d')][] = $event->title;
                        $cursor = $cursor->modify('+1 day');
                    }
                }
            }

            $columns[] = [
                'section_id' => $section['id'],
                'label' => $section['name'] ?? $section['desk_code'],
                'color' => $calendar !== null ? ($colors[$calendar->id] ?? null) : null,
                'events_by_day' => $eventsByDay,
            ];
        }

        return $columns;
    }

    /**
     * Sorted future-first (descending run_at) for display; the single
     * nearest upcoming transition is flagged is_next for highlighting.
     * Capped at 10 entries per page.
     *
     * @return array{entries: array<int, array{date: string, run_at: string, label: string, status: string, is_next: bool}>, page: int, total_pages: int}
     */
    private function buildPlannedTransitions(int $year, int $month, int $scoutYearId, int $page = 1): array
    {
        $monthPrefix = sprintf('%04d-%02d', $year, $month);
        $rows = array_values(array_filter(
            $this->schedulerService->findAllForTask(OnCallService::MODULE_ID, OnCallService::TASK_KEY, 500),
            fn(array $row) => str_starts_with((string) $row['reference'], $monthPrefix) && $row['status'] !== 'canceled'
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

            $runAt = new \DateTimeImmutable((string) $row['run_at']);
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
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
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
            if ($month >= 1 && $month <= 12) {
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
