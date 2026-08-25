<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Module\ModuleManager;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Service\IntegerInput;
use Core\View\MonthGrid\MonthGridBuilder;
use Core\View\SectionPickerHelper;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Twig\Environment;

class CalendarChiefController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private CalendarService $calendarService,
        private CalendarPickerService $calendarPickerService,
        private MonthGridBuilder $monthGridBuilder,
        private CalendarEventService $calendarEventService,
        private SectionService $sectionService,
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        private SettingService $settingService,
        private ModuleManager $moduleManager,
        private SectionStaffAuthorizationService $sectionStaffAuthorizationService
    ) {
    }

    /**
     * The sections this account animates, for the effective scout year —
     * the narrowing every write below applies on top of the route's own
     * role_min: chief (ARCHITECTURE.md §8.33, SECURITY.md §3). Resolved
     * here rather than in the Service, which never reads the session.
     *
     * @return int[]
     */
    private function staffedSectionIds(Role $role): array
    {
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);

        return array_map(
            static fn(array $section): int => (int) $section['id'],
            $this->sectionStaffAuthorizationService->getStaffedSections(
                AuthSession::getEmail() ?? '',
                $role->value,
                $effectiveYear->id
            )
        );
    }

    /**
     * GET /chefs/calendar — month grid for either "Mes évènements" (default)
     * or a single calendar picked from the calendar-picker — the same
     * shared component (Service\CalendarPickerService) the public page
     * uses, just scoped to editable calendars instead of visible ones.
     * Two different sets, deliberately: the picker and the month grid show
     * everything this role may SEE (getViewableCalendars() — an animateur
     * of one section still follows the whole unit), while the add/edit
     * dialog's own calendar list is what they may WRITE to
     * (getEditableCalendars(), narrowed to the sections they animate).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->calendarService->ensureSectionCalendars();
        $this->calendarService->ensureDefaultCalendar();

        $role = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role);
        $email = AuthSession::getEmail() ?? '';

        $staffedSectionIds = $this->staffedSectionIds($role);
        $viewableCalendars = $this->calendarEventService->getViewableCalendars($role);
        $editableCalendars = $this->calendarEventService->getEditableCalendars($role, $staffedSectionIds);

        $calendarOptions = $this->calendarPickerService->buildOptions($viewableCalendars);
        $selectedCalendarId = $this->calendarPickerService->resolveSelectedCalendarId(
            $request->getQuery('calendar'),
            $viewableCalendars
        );

        [$year, $month] = $this->resolveRequestedMonth($request->getQuery('month'));

        $calendarIdsForGrid = $this->calendarPickerService->resolveCalendarIdsForGrid(
            $selectedCalendarId,
            $viewableCalendars,
            $email,
            $effectiveYear->id
        );

        $events = $this->calendarService->getEventsForGrid($year, $month, $calendarIdsForGrid);
        $weeks = $this->monthGridBuilder->build(
            $year,
            $month,
            $this->calendarService->toGridEvents($events, $role, $email !== '' ? $email : null, $effectiveYear->id)
        );

        // The add-event modal's default calendar: whatever is currently
        // selected in the picker, since that's unambiguous — except on
        // "Mes évènements" (no single calendar to default to), which falls
        // back to the highest-role linked member's section, same as the
        // old per-page default before the picker was unified.
        if ($selectedCalendarId !== CalendarPickerService::MY_EVENTS_ID) {
            $defaultCalendarId = $selectedCalendarId;
        } else {
            $allSections = $this->sectionService->getAllWithBranches();
            $linkedMembers = $this->memberService->getLinkedMembers($email, $effectiveYear->id);
            $defaultSectionId = SectionPickerHelper::resolveDefault(null, $linkedMembers, $allSections);
            $defaultCalendarId = null;
            foreach ($editableCalendars as $calendar) {
                if ($calendar->sectionId === $defaultSectionId) {
                    $defaultCalendarId = $calendar->id;
                    break;
                }
            }
            if ($defaultCalendarId === null && count($editableCalendars) > 0) {
                $defaultCalendarId = $editableCalendars[0]->id;
            }
        }

        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear = $month === 12 ? $year + 1 : $year;

        // The calendar picker changes what this page shows without changing
        // its URL structure (?calendar={id}) — the breadcrumb's own segment
        // must reflect the current selection, same as Staffs/Trombinoscope.
        $selectedCalendarLabel = null;
        foreach ($calendarOptions as $option) {
            if ($option['id'] === $selectedCalendarId) {
                $selectedCalendarLabel = $option['label'];
                break;
            }
        }

        $editableCalendarIds = array_map(static fn($c) => $c->id, $editableCalendars);

        $context = [
            'calendar_options' => $calendarOptions,
            // The dialog's own calendar list — the write set, not the view
            // set. Same labels, built through the same picker service.
            'editable_calendar_options' => $this->calendarPickerService->buildOptions($editableCalendars),
            'editable_calendar_ids' => $editableCalendarIds,
            // An animateur with no Desk function on any section: every
            // section calendar is read-only for them, and the dialog would
            // be the only place that said so. Note this is NOT "nothing is
            // editable" — supplementary calendars carry no section, so
            // "Animateurs" stays open to them.
            'no_staffed_section' => $staffedSectionIds === [],
            'selected_calendar_id' => $selectedCalendarId,
            'default_calendar_id' => $defaultCalendarId,
            'year' => $year,
            'month' => $month,
            'month_label' => $this->monthLabel($year, $month),
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'prev_month_param' => sprintf('%04d-%02d', $prevYear, $prevMonth),
            'next_month_param' => sprintf('%04d-%02d', $nextYear, $nextMonth),
            'weeks' => $weeks,
            'default_title' => (string) $this->settingService->get('event_default_title', 'calendar', 'Réunion'),
            'default_start_time' => (string) $this->settingService->get('event_default_start_time', 'calendar', '14:00'),
            'default_end_time' => (string) $this->settingService->get('event_default_end_time', 'calendar', '17:45'),
            'default_location' => (string) $this->settingService->get('event_default_location', 'calendar', ''),
            'retro_module_active' => in_array('retro', $this->moduleManager->getEnabledModuleIds(), true),
        ];
        if ($selectedCalendarLabel !== null) {
            $context['breadcrumb_current'] = 'Calendrier · ' . $selectedCalendarLabel;
        }

        return $this->render('@calendar/chief.html.twig', $context);
    }

    /**
     * POST /chefs/calendar/event-create (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function createEvent(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $calendarId = IntegerInput::id($data['calendar_id'] ?? null);
        if ($calendarId === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        $role = Role::fromString(AuthSession::getRole());

        try {
            $event = $this->calendarEventService->createEvent(
                $calendarId,
                (string) ($data['title'] ?? ''),
                (string) ($data['start_date'] ?? ''),
                $this->stringOrNull($data['end_date'] ?? null),
                $this->stringOrNull($data['start_time'] ?? null),
                $this->stringOrNull($data['end_time'] ?? null),
                $this->stringOrNull($data['location'] ?? null),
                $this->stringOrNull($data['description'] ?? null),
                AuthSession::getUserAccountId(),
                ($data['auto_create_retro'] ?? false) === true,
                $role,
                $this->staffedSectionIds($role)
            );
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'event_created',
            'info',
            "Évènement « {$event->title} » créé",
            ['event_id' => $event->id, 'calendar_id' => $event->calendarId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'event_id' => $event->id]);
    }

    /**
     * POST /chefs/calendar/event-update (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateEvent(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $eventId = IntegerInput::id($data['event_id'] ?? null);
        $calendarId = IntegerInput::id($data['calendar_id'] ?? null);
        if ($eventId === null || $calendarId === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        $role = Role::fromString(AuthSession::getRole());

        try {
            $event = $this->calendarEventService->updateEvent(
                $eventId,
                $calendarId,
                (string) ($data['title'] ?? ''),
                (string) ($data['start_date'] ?? ''),
                $this->stringOrNull($data['end_date'] ?? null),
                $this->stringOrNull($data['start_time'] ?? null),
                $this->stringOrNull($data['end_time'] ?? null),
                $this->stringOrNull($data['location'] ?? null),
                $this->stringOrNull($data['description'] ?? null),
                ($data['auto_create_retro'] ?? false) === true,
                AuthSession::getUserAccountId(),
                $role,
                $this->staffedSectionIds($role)
            );
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'event_updated',
            'info',
            "Évènement « {$event->title} » modifié",
            ['event_id' => $event->id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /chefs/calendar/event-delete (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function deleteEvent(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $eventId = IntegerInput::id($data['event_id'] ?? null);
        if ($eventId === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        try {
            $role = Role::fromString(AuthSession::getRole());
            $this->calendarEventService->deleteEvent($eventId, $role, $this->staffedSectionIds($role));
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'event_deleted',
            'info',
            'Évènement supprimé',
            ['event_id' => $eventId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
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

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        return $value === '' ? null : $value;
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
