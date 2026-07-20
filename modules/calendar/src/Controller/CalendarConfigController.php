<?php

declare(strict_types=1);

namespace Modules\Calendar\Controller;

use Core\Config\SettingException;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use Twig\Environment;

class CalendarConfigController extends AbstractController
{
    private const SETTING_KEYS = [
        'event_default_title',
        'event_default_start_time',
        'event_default_end_time',
        'event_default_location',
    ];

    public function __construct(
        protected Environment $twig,
        private CalendarService $calendarService,
        private SectionService $sectionService,
        private SettingService $settingService,
        private JournalService $journalService,
        private CalendarNotificationService $notificationService
    ) {
    }

    /**
     * GET /config/calendar — the four sections described in module spec §5,
     * in order: event defaults, section calendars, supplementary
     * calendars, unité complète ICS link.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->calendarService->ensureSectionCalendars();
        $this->calendarService->ensureDefaultCalendar();

        $sectionsById = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionsById[$section['id']] = $section;
        }

        $sectionCalendars = [];
        foreach ($this->calendarService->getSectionCalendars() as $calendar) {
            $section = $calendar->sectionId !== null ? ($sectionsById[$calendar->sectionId] ?? null) : null;
            $sectionCalendars[] = [
                'calendar' => $calendar,
                'section_name' => $section !== null ? ($section['name'] ?? $section['desk_code']) : $calendar->sectionId,
                'color' => $section !== null ? SectionService::colorForSection($section) : null,
            ];
        }

        $calendarIdsWithEvents = $this->calendarService->getCalendarIdsWithEvents();

        return $this->render('@calendar/config.html.twig', [
            'default_title' => (string) $this->settingService->get('event_default_title', 'calendar', 'Réunion'),
            'default_start_time' => (string) $this->settingService->get('event_default_start_time', 'calendar', '14:00'),
            'default_end_time' => (string) $this->settingService->get('event_default_end_time', 'calendar', '16:00'),
            'default_location' => (string) $this->settingService->get('event_default_location', 'calendar', ''),
            'notify_multiday_events_enabled' => $this->notificationService->isEnabled(),
            'notify_multiday_events_days_before' => $this->notificationService->getDaysBefore(),
            'section_calendars' => $sectionCalendars,
            'supplementary_calendars' => $this->calendarService->getSupplementaryCalendars(),
            'calendar_ids_with_events' => $calendarIdsWithEvents,
            'unit_feed_token' => $this->calendarService->getOrCreateUnitFeedToken(),
        ]);
    }

    /**
     * POST /config/calendar/defaults — update the four event default values
     * (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateDefaults(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        foreach (self::SETTING_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            try {
                $this->settingService->set($key, (string) $data[$key], 'calendar');
            } catch (SettingException $e) {
                return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
            }
        }

        $this->journalService->log(
            'calendar',
            'defaults_updated',
            'info',
            'Valeurs par défaut du calendrier modifiées',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/calendar/notifications — enable/disable the multi-day
     * event reminder and set its lead time (AJAX, JSON). Changing either
     * value resyncs every section calendar's future multi-day events
     * immediately (Service\CalendarNotificationService::setEnabled()/
     * setDaysBefore()).
     *
     * @param array<string, string> $params
     */
    public function updateNotificationSettings(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            if (array_key_exists('enabled', $data)) {
                $this->notificationService->setEnabled((bool) $data['enabled']);
            }
            if (array_key_exists('days_before', $data)) {
                $this->notificationService->setDaysBefore((int) $data['days_before']);
            }
        } catch (CalendarException|SettingException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'notification_settings_updated',
            'info',
            'Réglages de notifications du calendrier modifiés',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/calendar/visibility — change a calendar's visibility
     * (section or supplementary) (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateVisibility(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $calendarId = (int) ($data['calendar_id'] ?? 0);
        $visibility = (string) ($data['visibility'] ?? '');

        try {
            $this->calendarService->updateVisibility($calendarId, $visibility);
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'visibility_changed',
            'info',
            'Visibilité de calendrier modifiée',
            ['calendar_id' => $calendarId, 'visibility' => $visibility],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/calendar/add — create a custom supplementary calendar
     * (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function addCalendar(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $calendar = $this->calendarService->addCalendar(
                (string) ($data['name'] ?? ''),
                (string) ($data['visibility'] ?? Calendar::VISIBILITY_PUBLIC)
            );
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'calendar_created',
            'info',
            "Calendrier « {$calendar->name} » créé",
            ['calendar_id' => $calendar->id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'calendar_id' => $calendar->id]);
    }

    /**
     * POST /config/calendar/regenerate — regenerate a supplementary
     * calendar's ICS token. The client MUST confirm before calling this —
     * regeneration always invalidates the previous link immediately
     * (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function regenerateToken(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $calendarId = (int) ($data['calendar_id'] ?? 0);

        try {
            $calendar = $this->calendarService->regenerateToken($calendarId);
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'token_regenerated',
            'security',
            'Lien ICS de calendrier régénéré',
            ['calendar_id' => $calendarId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'ics_token' => $calendar->icsToken]);
    }

    /**
     * POST /config/calendar/delete — delete a custom, non-default,
     * unused supplementary calendar (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function deleteCalendar(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $calendarId = (int) ($data['calendar_id'] ?? 0);

        try {
            $this->calendarService->delete($calendarId);
        } catch (CalendarException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'calendar',
            'calendar_deleted',
            'info',
            'Calendrier supprimé',
            ['calendar_id' => $calendarId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/calendar/unit-token/regenerate — regenerate the "unité
     * complète" feed token. Client MUST confirm before calling this (AJAX,
     * JSON).
     *
     * @param array<string, string> $params
     */
    public function regenerateUnitToken(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $token = $this->calendarService->regenerateUnitFeedToken();

        $this->journalService->log(
            'calendar',
            'unit_token_regenerated',
            'security',
            'Lien ICS unité complète régénéré',
            [],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'token' => $token]);
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
}
