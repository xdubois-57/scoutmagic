<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;

/**
 * The calendar's read API over a fixed list, visible to anybody.
 */
final class FakeCalendar implements CalendarEventLookupInterface
{
    /**
     * @param list<EventSummary> $events
     */
    public function __construct(private array $events)
    {
    }

    public function findEventsInWindow(\DateTimeInterface $windowStart, \DateTimeInterface $windowEnd, ?int $sectionId, Role $viewerRole): array
    {
        return $this->events;
    }

    public function findEventById(int $eventId, Role $viewerRole): ?EventSummary
    {
        foreach ($this->events as $event) {
            if ($event->id === $eventId) {
                return $event;
            }
        }

        return null;
    }

    public function sectionActivityForMonth(int $year, int $month, Role $viewerRole): array
    {
        return [];
    }

    public function searchUpcomingEvents(string $query, Role $viewerRole, int $limit = 20): array
    {
        $found = [];
        foreach ($this->events as $event) {
            if ($query === '' || str_contains(mb_strtolower($event->title), mb_strtolower($query))) {
                $found[] = $event;
            }
        }

        return array_slice($found, 0, $limit);
    }
}
