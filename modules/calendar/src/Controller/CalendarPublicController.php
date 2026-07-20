<?php

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\MonthGrid\MonthGridBuilder;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\IcsBuilder;
use Modules\Calendar\Service\PersonalFeedService;
use Twig\Environment;

class CalendarPublicController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private CalendarService $calendarService,
        private CalendarPickerService $calendarPickerService,
        private MonthGridBuilder $monthGridBuilder,
        private PersonalFeedService $personalFeedService,
        private IcsBuilder $icsBuilder,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /calendar — public calendar page. Calendar picker is the same
     * component the chiefs page uses (Service\CalendarPickerService):
     * "Mes évènements" (default) resolves via the visitor's own session
     * email — for an anonymous visitor that degrades gracefully to public
     * calendars only — plus one entry per calendar visible to their role.
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

        $eligibleCalendars = $this->calendarService->getVisibleCalendars($role);
        $calendarOptions = $this->calendarPickerService->buildOptions($eligibleCalendars);
        $selectedCalendarId = $this->calendarPickerService->resolveSelectedCalendarId(
            $request->getQuery('calendar'),
            $eligibleCalendars
        );

        [$year, $month] = $this->resolveRequestedMonth($request->getQuery('month'));

        $calendarIds = $this->calendarPickerService->resolveCalendarIdsForGrid($selectedCalendarId, $eligibleCalendars, $email, $effectiveYear->id);

        $events = $this->calendarService->getEventsForGrid($year, $month, $calendarIds);
        $weeks = $this->monthGridBuilder->build($year, $month, $this->calendarService->toGridEvents($events));

        // Reuses the same GridEvent shape as the grid bars (label, color,
        // and the full data bag incl. calendar-label) so the "Prochains
        // évènements" list and the detail dialog can show which calendar
        // an event belongs to without a separate data structure.
        $upcoming = $this->calendarService->toGridEvents($this->calendarService->getUpcomingEvents($calendarIds, 10));

        $isAuthenticated = AuthSession::isAuthenticated();
        $personalToken = null;
        if ($isAuthenticated) {
            $userAccountId = AuthSession::getUserAccountId();
            \assert($userAccountId !== null);
            $personalToken = $this->personalFeedService->getOrCreateToken($userAccountId);
        }

        $prevMonth = $month === 1 ? 12 : $month - 1;
        $prevYear = $month === 1 ? $year - 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear = $month === 12 ? $year + 1 : $year;

        return $this->render('@calendar/public.html.twig', [
            'calendar_options' => $calendarOptions,
            'selected_calendar_id' => $selectedCalendarId,
            'year' => $year,
            'month' => $month,
            'month_label' => $this->monthLabel($year, $month),
            'month_param' => sprintf('%04d-%02d', $year, $month),
            'prev_month_param' => sprintf('%04d-%02d', $prevYear, $prevMonth),
            'next_month_param' => sprintf('%04d-%02d', $nextYear, $nextMonth),
            'weeks' => $weeks,
            'upcoming_events' => $upcoming,
            'is_authenticated' => $isAuthenticated,
            'personal_token' => $personalToken,
            'effective_scout_year_id' => $effectiveYear->id,
        ]);
    }

    /**
     * GET /calendar/feed/{token}.ics — a single supplementary calendar's
     * ICS feed. Reachable by anyone with the link — visibility only
     * restricts display on the public page (see module.json/spec §2).
     *
     * @param array<string, string> $params
     */
    public function calendarFeed(Request $request, array $params): Response
    {
        $calendar = $this->calendarService->findByIcsToken((string) ($params['token'] ?? ''));
        if ($calendar === null) {
            return (new Response('Calendrier introuvable.', 404))->setHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $this->icsResponse($calendar->name ?? 'Calendrier', $this->calendarService->getAllEventsForCalendar($calendar->id));
    }

    /**
     * GET /calendar/feed/unit/{token}.ics — aggregate feed of every
     * configured calendar (sections + supplementary).
     *
     * @param array<string, string> $params
     */
    public function unitFeed(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        if (!$this->calendarService->isValidUnitFeedToken($token)) {
            return (new Response('Lien invalide.', 404))->setHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $allCalendarIds = array_map(
            fn($c) => $c->id,
            [...$this->calendarService->getSectionCalendars(), ...$this->calendarService->getSupplementaryCalendars()]
        );

        $events = [];
        foreach ($allCalendarIds as $calendarId) {
            array_push($events, ...$this->calendarService->getAllEventsForCalendar($calendarId));
        }

        return $this->icsResponse('Unité complète', $events);
    }

    /**
     * GET /calendar/feed/personal/{token}.ics — personal feed. Always
     * returns a syntactically valid (possibly empty) calendar — an unknown
     * token or a visitor with nothing to show is not an error.
     *
     * @param array<string, string> $params
     */
    public function personalFeed(Request $request, array $params): Response
    {
        $token = (string) ($params['token'] ?? '');
        $effectiveYear = $this->scoutYearResolver->getCurrentPublicYear();

        $events = $this->personalFeedService->getEventsForToken($token, $effectiveYear['id']);

        return $this->icsResponse('Mon calendrier', $events);
    }

    /**
     * POST /calendar/personal-token/regenerate — invalidate the visitor's
     * current personal ICS link and issue a new one (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function regeneratePersonalToken(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        $token = $this->personalFeedService->regenerateToken($userAccountId);

        $this->journalService->log(
            'calendar',
            'personal_token_regenerated',
            'info',
            'Lien ICS personnel régénéré',
            [],
            $userAccountId
        );

        return $this->json(['success' => true, 'token' => $token]);
    }

    /**
     * @param \Modules\Calendar\Repository\CalendarEvent[] $events
     */
    private function icsResponse(string $name, array $events): Response
    {
        $ics = $this->icsBuilder->build($name, $events);

        return (new Response($ics))
            ->setHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="calendrier.ics"');
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
