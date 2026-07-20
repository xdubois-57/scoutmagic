<?php

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
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
        private SettingService $settingService
    ) {
    }

    /**
     * GET /chefs/calendar — month grid for either "Mes évènements" (default)
     * or a single calendar picked from the calendar-picker — the same
     * shared component (Service\CalendarPickerService) the public page
     * uses, just scoped to editable calendars instead of visible ones.
     * Every event on this page is editable — chiefs have blanket
     * create/edit access to every calendar
     * (CalendarEventService::getEditableCalendarsForChief()).
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

        $editableCalendars = $this->calendarEventService->getEditableCalendarsForChief($role);
        $calendarOptions = $this->calendarPickerService->buildOptions($editableCalendars);
        $selectedCalendarId = $this->calendarPickerService->resolveSelectedCalendarId(
            $request->getQuery('calendar'),
            $editableCalendars
        );

        [$year, $month] = $this->resolveRequestedMonth($request->getQuery('month'));

        $calendarIdsForGrid = $this->calendarPickerService->resolveCalendarIdsForGrid(
            $selectedCalendarId,
            $editableCalendars,
            $email,
            $effectiveYear->id
        );

        $events = $this->calendarService->getEventsForGrid($year, $month, $calendarIdsForGrid);
        $weeks = $this->monthGridBuilder->build($year, $month, $this->calendarService->toGridEvents($events));

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

        return $this->render('@calendar/chief.html.twig', [
            'calendar_options' => $calendarOptions,
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
            'default_end_time' => (string) $this->settingService->get('event_default_end_time', 'calendar', '16:00'),
            'default_location' => (string) $this->settingService->get('event_default_location', 'calendar', ''),
        ]);
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

        try {
            $event = $this->calendarEventService->createEvent(
                (int) ($data['calendar_id'] ?? 0),
                (string) ($data['title'] ?? ''),
                (string) ($data['start_date'] ?? ''),
                $this->stringOrNull($data['end_date'] ?? null),
                $this->stringOrNull($data['start_time'] ?? null),
                $this->stringOrNull($data['end_time'] ?? null),
                $this->stringOrNull($data['location'] ?? null),
                $this->stringOrNull($data['description'] ?? null),
                AuthSession::getUserAccountId()
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

        try {
            $event = $this->calendarEventService->updateEvent(
                (int) ($data['event_id'] ?? 0),
                (int) ($data['calendar_id'] ?? 0),
                (string) ($data['title'] ?? ''),
                (string) ($data['start_date'] ?? ''),
                $this->stringOrNull($data['end_date'] ?? null),
                $this->stringOrNull($data['start_time'] ?? null),
                $this->stringOrNull($data['end_time'] ?? null),
                $this->stringOrNull($data['location'] ?? null),
                $this->stringOrNull($data['description'] ?? null)
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

        $eventId = (int) ($data['event_id'] ?? 0);

        try {
            $this->calendarEventService->deleteEvent($eventId);
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
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
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
