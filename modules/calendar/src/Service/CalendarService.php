<?php

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Core\Member\SectionService;
use Core\Security\Role;
use Core\View\MonthGrid\GridEvent;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;

class CalendarService
{
    private const DEFAULT_CALENDAR_NAME = 'Animateurs';
    /** Fixed accent color (bordeaux) for every supplementary calendar's
     *  event bars (Animateurs, custom ones) — section calendars use their
     *  own color instead (see colorsByCalendarId()). */
    private const SUPPLEMENTARY_CALENDAR_COLOR = '#800020';

    /**
     * age_branches.sort_order values for Baladins/Louveteaux/Éclaireurs/
     * Pionniers (Core\Import\AgeBranchRepository::canonicalSortOrder) — the
     * only branches whose section calendars default to public visibility.
     * Every other branch (Staff d'U, Route, Iama, unknown) defaults to
     * chief-only — same "core animés branches" boundary as the SOS Staff
     * d'U module's default included-sections seeding.
     */
    private const PUBLIC_BY_DEFAULT_BRANCH_SORT_ORDERS = [10, 20, 30, 40];

    public function __construct(
        private CalendarRepository $calendarRepository,
        private CalendarEventRepository $eventRepository,
        private SectionService $sectionService,
        private CalendarUnitFeedTokenRepository $unitFeedTokenRepository
    ) {
    }

    /**
     * Idempotent: creates a calendar for every active, visible section that
     * doesn't have one yet (called on every relevant page load, same
     * pattern as Core\Badge\BadgeService::ensureDefaults()). Baladins,
     * Louveteaux, Éclaireurs, and Pionniers sections default to public
     * visibility; every other branch (Staff d'U, Route, Iama, unknown)
     * defaults to chief-only.
     */
    public function ensureSectionCalendars(): void
    {
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if ($this->calendarRepository->findBySectionId($section['id']) !== null) {
                continue;
            }
            $visibility = in_array($section['branch_sort_order'], self::PUBLIC_BY_DEFAULT_BRANCH_SORT_ORDERS, true)
                ? Calendar::VISIBILITY_PUBLIC
                : Calendar::VISIBILITY_CHIEF;
            $this->calendarRepository->createSectionCalendar($section['id'], $visibility);
        }
    }

    /**
     * Idempotent: creates the "Animateurs" default supplementary calendar
     * if missing. Chief-only visibility by default — it is meant for
     * identified animateurs, not anonymous public visitors; an admin can
     * still widen it from the config page like any other calendar.
     */
    public function ensureDefaultCalendar(): void
    {
        if ($this->calendarRepository->findDefaultCalendar() !== null) {
            return;
        }
        $this->calendarRepository->createSupplementaryCalendar(
            self::DEFAULT_CALENDAR_NAME,
            true,
            Calendar::VISIBILITY_CHIEF,
            $this->generateToken()
        );
    }

    /** @return Calendar[] */
    public function getSectionCalendars(): array
    {
        return $this->calendarRepository->findSectionCalendars();
    }

    /** @return Calendar[] */
    public function getSupplementaryCalendars(): array
    {
        return $this->calendarRepository->findSupplementaryCalendars();
    }

    public function findById(int $id): ?Calendar
    {
        return $this->calendarRepository->findById($id);
    }

    public function findByIcsToken(string $token): ?Calendar
    {
        return $this->calendarRepository->findByIcsToken($token);
    }

    /**
     * Calendars a viewer with the given effective role may see on the
     * public page: public calendars always, chief-only calendars for
     * chief+ roles, admin-only calendars for admin+ (chef d'unité) roles.
     * This is a display filter only — it never restricts the ICS feed
     * link, which stays reachable to anyone who has it (see §2 spec).
     *
     * @return Calendar[]
     */
    public function getVisibleCalendars(Role $viewerRole): array
    {
        return array_values(array_filter(
            $this->calendarRepository->findAll(),
            fn(Calendar $c) => $this->isVisibleToRole($c, $viewerRole)
        ));
    }

    public function isVisibleToRole(Calendar $calendar, Role $viewerRole): bool
    {
        return match ($calendar->visibility) {
            Calendar::VISIBILITY_PUBLIC => true,
            Calendar::VISIBILITY_CHIEF => $viewerRole->hasAccess(Role::CHIEF),
            Calendar::VISIBILITY_ADMIN => $viewerRole->hasAccess(Role::ADMIN),
            default => false,
        };
    }

    /**
     * Calendar ids to query for the public page: all calendars visible to
     * the viewer, or — when a specific section is selected — only that
     * section's own calendar (the section picker narrows to one section,
     * it doesn't additionally pull in supplementary calendars).
     *
     * @return int[]
     */
    public function resolveCalendarIdsForPublicPage(?int $selectedSectionId, Role $viewerRole): array
    {
        $visible = $this->getVisibleCalendars($viewerRole);

        if ($selectedSectionId !== null) {
            $visible = array_filter($visible, fn(Calendar $c) => $c->sectionId === $selectedSectionId);
        }

        return array_values(array_map(fn(Calendar $c) => $c->id, $visible));
    }

    /**
     * @throws CalendarException on invalid name or a name collision
     */
    public function addCalendar(string $name, string $visibility): Calendar
    {
        $name = trim($name);
        if ($name === '') {
            throw new CalendarException('Le nom du calendrier est obligatoire.');
        }
        $this->assertValidVisibility($visibility);

        foreach ($this->calendarRepository->findSupplementaryCalendars() as $existing) {
            if ($existing->name === $name) {
                throw new CalendarException('Un calendrier avec ce nom existe déjà.');
            }
        }

        $id = $this->calendarRepository->createSupplementaryCalendar($name, false, $visibility, $this->generateToken());
        $calendar = $this->calendarRepository->findById($id);
        \assert($calendar !== null);
        return $calendar;
    }

    /**
     * @throws CalendarException when the calendar doesn't exist or the
     *                           visibility value is invalid
     */
    public function updateVisibility(int $id, string $visibility): void
    {
        $calendar = $this->calendarRepository->findById($id);
        if ($calendar === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        $this->assertValidVisibility($visibility);
        $this->calendarRepository->updateVisibility($id, $visibility);
    }

    /**
     * Regenerate a supplementary calendar's ICS token, invalidating the
     * previous link immediately (existing subscriptions stop updating).
     * Section calendars have no individual ICS link.
     *
     * @throws CalendarException
     */
    public function regenerateToken(int $id): Calendar
    {
        $calendar = $this->calendarRepository->findById($id);
        if ($calendar === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        if ($calendar->isSectionCalendar()) {
            throw new CalendarException("Un calendrier de section n'a pas de lien ICS individuel.");
        }
        $this->calendarRepository->updateIcsToken($id, $this->generateToken());
        $updated = $this->calendarRepository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * Delete a supplementary, non-default calendar with no events. Section
     * calendars, the default "Animateurs" calendar, and any calendar that
     * already has events can never be deleted — only deactivated by
     * changing its visibility — mirroring
     * Core\Badge\BadgeService::delete()'s never-silently-lose-data guard.
     *
     * @throws CalendarException
     */
    public function delete(int $id): void
    {
        $calendar = $this->calendarRepository->findById($id);
        if ($calendar === null) {
            throw new CalendarException('Calendrier introuvable.');
        }
        if ($calendar->isSectionCalendar()) {
            throw new CalendarException('Un calendrier de section ne peut pas être supprimé.');
        }
        if ($calendar->isDefault) {
            throw new CalendarException('Le calendrier par défaut ne peut pas être supprimé.');
        }
        if ($this->eventRepository->calendarHasEvents($id)) {
            throw new CalendarException('Ce calendrier contient des évènements — il ne peut pas être supprimé.');
        }
        $this->calendarRepository->delete($id);
    }

    /**
     * Ids of supplementary calendars that have at least one event — used by
     * the admin UI to disable the delete button instead of letting the
     * user hit the server-side guard in delete().
     *
     * @return int[]
     */
    public function getCalendarIdsWithEvents(): array
    {
        $ids = [];
        foreach ($this->calendarRepository->findSupplementaryCalendars() as $calendar) {
            if ($this->eventRepository->calendarHasEvents($calendar->id)) {
                $ids[] = $calendar->id;
            }
        }
        return $ids;
    }

    /**
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function getEventsForMonth(array $calendarIds, int $year, int $month): array
    {
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = date('Y-m-t', (int) strtotime($from));
        return $this->eventRepository->findByCalendarIdsInRange($calendarIds, $from, $to);
    }

    /**
     * Events for the grid date range (the same Monday-on/before-the-1st to
     * Sunday-on/after-the-last-day span Core\View\MonthGrid\MonthGridBuilder
     * computes internally) — a thin fetch helper, kept here since it's the
     * only place that knows the calendar module's date/calendar-id
     * filtering; the grid *layout* itself lives in MonthGridBuilder.
     *
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function getEventsForGrid(int $year, int $month, array $calendarIds): array
    {
        if (count($calendarIds) === 0) {
            return [];
        }

        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');
        $isoWeekdayOfFirst = (int) $firstOfMonth->format('N');
        $gridStart = $firstOfMonth->modify('-' . ($isoWeekdayOfFirst - 1) . ' days');
        $isoWeekdayOfLast = (int) $lastOfMonth->format('N');
        $gridEnd = $lastOfMonth->modify('+' . (7 - $isoWeekdayOfLast) . ' days');

        return $this->eventRepository->findByCalendarIdsInRange($calendarIds, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
    }

    /**
     * Map calendar events into the generic
     * Core\View\MonthGrid\GridEvent shape MonthGridBuilder consumes. The
     * data bag mirrors what a page's click-to-{edit,view-details} dialog
     * reads off each bar's dataset (data-id, data-calendar-id,
     * data-calendar-label, ...) — harmless and unused on pages that don't
     * wire up any click behavior.
     *
     * @param CalendarEvent[] $events
     * @return GridEvent[]
     */
    public function toGridEvents(array $events): array
    {
        $colors = $this->colorsByCalendarId();
        $labels = $this->labelsByCalendarId();

        return array_map(function (CalendarEvent $event) use ($colors, $labels): GridEvent {
            $calendarLabel = $labels[$event->calendarId] ?? 'Calendrier';

            $tooltip = $event->title;
            if ($event->startTime !== null) {
                $tooltip .= ' — ' . substr($event->startTime, 0, 5);
            }
            if ($event->location !== null) {
                $tooltip .= ' — ' . $event->location;
            }
            $tooltip .= " ({$calendarLabel})";

            return new GridEvent(
                id: (string) $event->id,
                startDate: $event->startDate,
                endDate: $event->endDate,
                label: $event->title,
                color: $colors[$event->calendarId] ?? '#6c757d',
                tooltip: $tooltip,
                data: [
                    'id' => (string) $event->id,
                    'calendar-id' => (string) $event->calendarId,
                    'calendar-label' => $calendarLabel,
                    'title' => $event->title,
                    'start-date' => $event->startDate,
                    'end-date' => $event->endDate ?? '',
                    'start-time' => $event->startTime !== null ? substr($event->startTime, 0, 5) : '',
                    'end-time' => $event->endTime !== null ? substr($event->endTime, 0, 5) : '',
                    'location' => $event->location ?? '',
                    'description' => $event->description ?? '',
                ]
            );
        }, $events);
    }

    /**
     * @param int[] $calendarIds
     * @return CalendarEvent[]
     */
    public function getUpcomingEvents(array $calendarIds, int $limit = 10): array
    {
        return $this->eventRepository->findUpcoming($calendarIds, (new \DateTimeImmutable())->format('Y-m-d'), $limit);
    }

    /**
     * Every event ever created in a calendar, any date — used to build a
     * full ICS feed (unlike getEventsForMonth()/getUpcomingEvents(), which
     * are for the on-page month grid and "next events" list only).
     *
     * @return CalendarEvent[]
     */
    public function getAllEventsForCalendar(int $calendarId): array
    {
        return $this->eventRepository->findByCalendarId($calendarId);
    }

    /**
     * Idempotent: returns the existing "unité complète" feed token, or
     * creates one on first access.
     */
    public function getOrCreateUnitFeedToken(): string
    {
        $existing = $this->unitFeedTokenRepository->findToken();
        if ($existing !== null) {
            return $existing;
        }
        $token = $this->generateToken();
        $this->unitFeedTokenRepository->setToken($token);
        return $token;
    }

    /**
     * Regenerate the "unité complète" feed token, invalidating the
     * previous link immediately.
     */
    public function regenerateUnitFeedToken(): string
    {
        $token = $this->generateToken();
        $this->unitFeedTokenRepository->setToken($token);
        return $token;
    }

    public function isValidUnitFeedToken(string $token): bool
    {
        return $token !== '' && $this->unitFeedTokenRepository->tokenExists($token);
    }

    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Event-bar color for every known calendar: section calendars use
     * SectionService::colorForSection() (branch color, or the dedicated
     * "Staff d'U" color — same helper the admin page, the section pickers,
     * and trombinoscope all use, so a section's color is consistent
     * everywhere it appears); supplementary calendars all use one fixed
     * accent (bordeaux) so they're visually distinguishable from section
     * events at a glance.
     *
     * @return array<int, string> calendarId => hex color
     */
    public function colorsByCalendarId(): array
    {
        $sectionsById = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionsById[$section['id']] = $section;
        }

        $colors = [];
        foreach ($this->calendarRepository->findAll() as $calendar) {
            $section = $calendar->sectionId !== null ? ($sectionsById[$calendar->sectionId] ?? null) : null;
            $colors[$calendar->id] = $section !== null
                ? SectionService::colorForSection($section)
                : self::SUPPLEMENTARY_CALENDAR_COLOR;
        }
        return $colors;
    }

    /**
     * Display label for every known calendar: a section calendar's label
     * is its section's name (or desk_code if unnamed), a supplementary
     * calendar's label is its own name — the single source of truth for
     * "what do we call this calendar", used by the calendar-picker
     * (Service\CalendarPickerService::buildOptions()) and by
     * toGridEvents() (so a bar's/upcoming-event's own data carries which
     * calendar it belongs to, e.g. for the public page's event list and
     * detail dialog).
     *
     * @return array<int, string> calendarId => label
     */
    public function labelsByCalendarId(): array
    {
        $sectionsById = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $sectionsById[$section['id']] = $section;
        }

        $labels = [];
        foreach ($this->calendarRepository->findAll() as $calendar) {
            if ($calendar->sectionId !== null) {
                $section = $sectionsById[$calendar->sectionId] ?? null;
                $labels[$calendar->id] = $section !== null ? ($section['name'] ?? $section['desk_code']) : 'Section';
                continue;
            }
            $labels[$calendar->id] = $calendar->name ?? 'Calendrier';
        }
        return $labels;
    }

    private function assertValidVisibility(string $visibility): void
    {
        $valid = [Calendar::VISIBILITY_PUBLIC, Calendar::VISIBILITY_CHIEF, Calendar::VISIBILITY_ADMIN];
        if (!in_array($visibility, $valid, true)) {
            throw new CalendarException('Visibilité invalide.');
        }
    }
}
