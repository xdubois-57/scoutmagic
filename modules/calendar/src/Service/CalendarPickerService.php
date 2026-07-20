<?php

declare(strict_types=1);

namespace Modules\Calendar\Service;

use Modules\Calendar\Repository\Calendar;

/**
 * The calendar-picker: option-list building, selection resolution, and
 * "Mes évènements" scope resolution — shared by the public page and the
 * chiefs page (module spec: "the code defining the calendar list and its
 * picker rendering must be the same on both pages"). The two pages differ
 * only in which calendars are *eligible* to appear (visible-to-role for
 * the public page, editable-for-chief for the chiefs page) — that set is
 * supplied by the caller, everything else here is identical.
 *
 * "Mes évènements" always resolves via
 * PersonalFeedService::resolveCalendarIdsForEmail(currentEmail ?? '', ...)
 * — for an authenticated identified/intendant/chief viewer this is their
 * real personal scope; for an anonymous visitor (empty email) it
 * degrades gracefully to "public calendars only" with no special-casing,
 * since RoleResolver::resolve('') and MemberService::getLinkedMembers('')
 * both come back empty.
 */
class CalendarPickerService
{
    /** Sentinel calendar-picker entry: not a real calendar id (ids are
     *  auto-increment, always >= 1). */
    public const MY_EVENTS_ID = 0;
    public const MY_EVENTS_LABEL = 'Mes évènements';

    public function __construct(
        private CalendarService $calendarService,
        private PersonalFeedService $personalFeedService
    ) {
    }

    /**
     * @param Calendar[] $eligibleCalendars
     * @return array<int, array{id: int, label: string, color: ?string}>
     */
    public function buildOptions(array $eligibleCalendars): array
    {
        $labels = $this->calendarService->labelsByCalendarId();
        $colors = $this->calendarService->colorsByCalendarId();

        $options = [['id' => self::MY_EVENTS_ID, 'label' => self::MY_EVENTS_LABEL, 'color' => null]];
        foreach ($eligibleCalendars as $calendar) {
            $options[] = [
                'id' => $calendar->id,
                'label' => $labels[$calendar->id] ?? 'Calendrier',
                'color' => $colors[$calendar->id] ?? null,
            ];
        }

        return $options;
    }

    /**
     * Validate a requested calendar id against the eligible set, falling
     * back to "Mes évènements" for anything missing/invalid.
     *
     * @param Calendar[] $eligibleCalendars
     */
    public function resolveSelectedCalendarId(mixed $requestedId, array $eligibleCalendars): int
    {
        if ($requestedId === null || $requestedId === '') {
            return self::MY_EVENTS_ID;
        }

        $requestedId = (int) $requestedId;
        foreach ($eligibleCalendars as $calendar) {
            if ($calendar->id === $requestedId) {
                return $requestedId;
            }
        }

        return self::MY_EVENTS_ID;
    }

    /**
     * "Mes évènements" for a genuinely anonymous visitor (no session at
     * all — $email === '') falls back to every eligible calendar rather
     * than resolving through PersonalFeedService: an empty email has no
     * linked sections to narrow to, so a literal personal-feed lookup
     * would show almost nothing, which defeats the public page's purpose
     * (showing the unit's activities to visitors who aren't logged in
     * yet). An authenticated viewer with genuinely zero linked members
     * still gets the real (possibly narrow) personal scope — that's
     * accurate for them, not a bug.
     *
     * @param Calendar[] $eligibleCalendars
     * @return int[]
     */
    public function resolveCalendarIdsForGrid(int $selectedCalendarId, array $eligibleCalendars, string $email, int $scoutYearId): array
    {
        if ($selectedCalendarId !== self::MY_EVENTS_ID) {
            return [$selectedCalendarId];
        }

        if ($email === '') {
            return array_map(fn(Calendar $c) => $c->id, $eligibleCalendars);
        }

        return $this->personalFeedService->resolveCalendarIdsForEmail($email, $scoutYearId);
    }
}
