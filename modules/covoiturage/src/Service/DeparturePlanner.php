<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Service;

use Core\Geo\AddressLocator;
use Core\Geo\AddressLookup;
use Core\Geo\GeocodingThrottle;
use Core\Geo\GeoPoint;
use Core\Geo\RoutingService;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Repository\Carpool;

/**
 * The departure time the offer form suggests (#703), and how it was
 * obtained.
 *
 * - **Outbound**: from the EARLIEST start among the linked events of the
 *   outbound day — a party replicated on several section calendars may
 *   carry several hours, and the earliest makes nobody late. Start minus
 *   (travel + {@see MARGIN_MINUTES}) when a route was found, start minus
 *   {@see FALLBACK_MINUTES} in every other case, whatever the reason.
 * - **Return**: the LATEST end among the linked events of the return day;
 *   the estimated arrival is that plus the same travel time, and **no
 *   arrival is invented** when the travel is unknown.
 *
 * A whole-day event has no hour (`EventSummary::$startTime` null) and is
 * left out rather than read as midnight. With no timed event on the day
 * there is nothing to count back from, so nothing is suggested and the
 * field stays as the driver leaves it.
 *
 * The travel is measured from the meeting point the driver typed — never
 * from the unit's premises, which only pre-fill that field — to the
 * carpool's place: its point when there is one, its address looked up
 * otherwise. Every failure (geocoding off or unavailable, no route, OSRM
 * down) is a null, and the 30-minute rule applies without a visible error.
 */
final class DeparturePlanner
{
    public const MARGIN_MINUTES = 5;
    public const FALLBACK_MINUTES = 30;

    /** How long the form's request may wait for the route server's slot. */
    private const ROUTING_WAIT_SECONDS = 3.0;

    public function __construct(
        private readonly ?CalendarEventLookupInterface $calendar,
        private readonly ?AddressLocator $locator = null,
        private readonly ?RoutingService $router = null,
        private readonly ?GeocodingThrottle $routingThrottle = null
    ) {
    }

    /** The earliest start among the carpool's events on its outbound day. */
    public function outboundStart(Carpool $carpool, Role $role): ?string
    {
        return self::earliestStart($this->events($carpool, $role), $carpool->outboundDate);
    }

    /** The latest end among the carpool's events on its return day. */
    public function returnEnd(Carpool $carpool, Role $role): ?string
    {
        return $carpool->returnDate === null
            ? null
            : self::latestEnd($this->events($carpool, $role), $carpool->returnDate);
    }

    /**
     * Minutes by car from what the driver typed to the carpool's place, or
     * null — whatever the reason.
     */
    public function travelMinutes(Carpool $carpool, string $from, int $accountId): ?int
    {
        if ($this->locator === null || $this->router === null || $this->routingThrottle === null) {
            return null;
        }

        $start = $this->locate($from, $accountId);
        $end = $carpool->point ?? $this->locate($carpool->address, $accountId);
        if ($start === null || $end === null) {
            return null;
        }

        $router = $this->router;
        [$outcome, $seconds] = $this->routingThrottle->runWaiting(
            static fn(): ?int => $router->drivingSeconds($start, $end),
            self::ROUTING_WAIT_SECONDS,
            static fn(): bool => true
        );

        return $outcome === GeocodingThrottle::RAN && is_int($seconds) ? (int) ceil($seconds / 60) : null;
    }

    /**
     * @param list<EventSummary> $events
     */
    public static function earliestStart(array $events, string $day): ?string
    {
        $times = [];
        foreach ($events as $event) {
            if ($event->startTime !== null && substr($event->startDate, 0, 10) === substr($day, 0, 10)) {
                $times[] = $event->startTime;
            }
        }
        sort($times);

        return $times[0] ?? null;
    }

    /**
     * @param list<EventSummary> $events
     */
    public static function latestEnd(array $events, string $day): ?string
    {
        $times = [];
        foreach ($events as $event) {
            // A whole-day event has no end hour either, whatever is stored.
            if ($event->startTime === null || $event->endTime === null) {
                continue;
            }
            if (substr($event->endDate, 0, 10) === substr($day, 0, 10)) {
                $times[] = $event->endTime;
            }
        }
        rsort($times);

        return $times[0] ?? null;
    }

    /**
     * @return array{time: string, line: string}|null
     */
    public static function outboundSuggestion(?string $eventStart, ?int $travelMinutes): ?array
    {
        if ($eventStart === null) {
            return null;
        }

        $before = $travelMinutes !== null ? $travelMinutes + self::MARGIN_MINUTES : self::FALLBACK_MINUTES;
        $time = self::shift($eventStart, -$before);
        $how = $travelMinutes !== null
            ? 'trajet estimé à ' . $travelMinutes . ' min, plus ' . self::MARGIN_MINUTES . ' min de marge'
            : 'trajet non calculé : ' . self::FALLBACK_MINUTES . ' min avant';

        return [
            'time' => $time,
            'line' => 'Heure suggérée : ' . CarpoolFormat::time($time) . ' — début à '
                . CarpoolFormat::time($eventStart) . ', ' . $how . '.',
        ];
    }

    /**
     * @return array{time: string, line: string, arrival: ?string}|null
     */
    public static function returnSuggestion(?string $eventEnd, ?int $travelMinutes): ?array
    {
        if ($eventEnd === null) {
            return null;
        }

        $arrival = $travelMinutes !== null ? self::shift($eventEnd, $travelMinutes) : null;

        return [
            'time' => $eventEnd,
            'arrival' => $arrival,
            'line' => 'Heure suggérée : ' . CarpoolFormat::time($eventEnd) . ', à la fin de l\'activité. '
                . ($arrival !== null
                    ? 'Arrivée estimée : ' . CarpoolFormat::time($arrival) . ' (trajet estimé à '
                        . $travelMinutes . ' min).'
                    : 'Arrivée non estimée : trajet non calculé.'),
        ];
    }

    /** `HH:MM` moved by $minutes, on a 24-hour clock. */
    private static function shift(string $hhmm, int $minutes): string
    {
        [$hours, $mins] = array_map('intval', explode(':', substr($hhmm, 0, 5)));
        $total = (($hours * 60 + $mins + $minutes) % 1440 + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private function locate(string $address, int $accountId): ?GeoPoint
    {
        $lookup = $this->locator?->locate($address, $accountId);

        return $lookup !== null && $lookup->status === AddressLookup::FOUND ? $lookup->point : null;
    }

    /**
     * The linked events, re-read through the calendar's contract with the
     * reader's role — the carpool's own copy carries no hour.
     *
     * @return list<EventSummary>
     */
    private function events(Carpool $carpool, Role $role): array
    {
        if ($this->calendar === null) {
            return [];
        }
        $events = [];
        foreach ($carpool->events as $linked) {
            $event = $this->calendar->findEventById($linked->eventId, $role);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
