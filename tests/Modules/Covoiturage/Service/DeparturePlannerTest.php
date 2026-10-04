<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage\Service;

use Core\Geo\GeoPoint;
use Core\Geo\RoutingService;
use Core\Security\Role;
use Modules\Calendar\Api\EventSummary;
use Modules\Covoiturage\Repository\Carpool;
use Modules\Covoiturage\Repository\CarpoolEvent;
use Modules\Covoiturage\Service\DeparturePlanner;
use PHPUnit\Framework\TestCase;

/**
 * The suggested departure of the offer form (#703): the rules, the choice
 * among several events of one day, the whole-day event left out, and the
 * silent fallback when the route cannot be had.
 */
class DeparturePlannerTest extends TestCase
{
    private const DAY = '2026-11-14';
    private const RETURN_DAY = '2026-11-15';

    private static function event(
        int $id,
        string $start,
        ?string $startTime,
        string $end,
        ?string $endTime
    ): EventSummary {
        return new EventSummary($id, 'Fête', 'Louveteaux', $start, $end, startTime: $startTime, endTime: $endTime);
    }

    public function testTheThirtyMinuteRuleWhenTheRouteIsUnknown(): void
    {
        $suggestion = DeparturePlanner::outboundSuggestion('14:00', null);

        $this->assertSame('13:30', $suggestion['time'] ?? null);
        $this->assertStringContainsString('trajet non calculé', $suggestion['line'] ?? '');
    }

    public function testTheTravelPlusFiveMinutesWhenTheRouteIsKnown(): void
    {
        $suggestion = DeparturePlanner::outboundSuggestion('14:00', 42);

        $this->assertSame('13:13', $suggestion['time'] ?? null);
        $this->assertSame(
            'Heure suggérée : 13 h 13 — début à 14 h 00, trajet estimé à 42 min, plus 5 min de marge.',
            $suggestion['line'] ?? null
        );
    }

    public function testTheEarliestStartAmongTheDaysEventsAndTheWholeDayOneLeftOut(): void
    {
        $events = [
            self::event(1, self::DAY, '14:30', self::DAY, '17:00'),
            self::event(2, self::DAY, '14:00', self::DAY, '18:00'),
            // Whole day: no hour, never midnight.
            self::event(3, self::DAY, null, self::DAY, null),
            // Another day.
            self::event(4, '2026-11-13', '09:00', '2026-11-13', '10:00'),
        ];

        $this->assertSame('14:00', DeparturePlanner::earliestStart($events, self::DAY));
    }

    public function testNoTimedEventOnTheDayMeansNoSuggestionRatherThanMidnight(): void
    {
        $events = [self::event(3, self::DAY, null, self::DAY, null)];

        $this->assertNull(DeparturePlanner::earliestStart($events, self::DAY));
        $this->assertNull(DeparturePlanner::outboundSuggestion(null, 42));
        $this->assertNull(DeparturePlanner::latestEnd($events, self::DAY));
    }

    public function testTheLatestEndOfTheReturnDayAndTheArrivalOnlyWhenTheRouteIsKnown(): void
    {
        $events = [
            // A weekend ending on the return day.
            self::event(1, self::DAY, '10:00', self::RETURN_DAY, '16:00'),
            self::event(2, self::RETURN_DAY, '09:00', self::RETURN_DAY, '17:30'),
            self::event(3, self::RETURN_DAY, null, self::RETURN_DAY, null),
        ];
        $end = DeparturePlanner::latestEnd($events, self::RETURN_DAY);
        $this->assertSame('17:30', $end);

        $known = DeparturePlanner::returnSuggestion($end, 42);
        $this->assertSame('17:30', $known['time'] ?? null);
        $this->assertSame('18:12', $known['arrival'] ?? null);
        $this->assertStringContainsString('Arrivée estimée : 18 h 12', $known['line'] ?? '');

        $unknown = DeparturePlanner::returnSuggestion($end, null);
        $this->assertNotNull($unknown);
        $this->assertNull($unknown['arrival'], 'no arrival is invented');
        $this->assertStringContainsString('Arrivée non estimée : trajet non calculé.', $unknown['line'] ?? '');
    }

    public function testTheEventsHoursAreReadThroughTheCalendarContract(): void
    {
        $calendar = $this->createStub(\Modules\Calendar\Api\CalendarEventLookupInterface::class);
        $calendar->method('findEventById')->willReturnCallback(static fn(int $id): ?EventSummary => match ($id) {
            501 => self::event(501, self::DAY, '14:30', self::DAY, '17:00'),
            502 => self::event(502, self::DAY, '14:00', self::RETURN_DAY, '16:00'),
            default => null,
        });
        $carpool = self::carpool([
            new CarpoolEvent(501, 'Fête — Louveteaux', 10, 'Louveteaux'),
            new CarpoolEvent(502, 'Fête — Éclaireurs', 20, 'Éclaireurs'),
        ]);

        $planner = new DeparturePlanner($calendar);

        $this->assertSame('14:00', $planner->outboundStart($carpool, Role::IDENTIFIED));
        $this->assertSame('16:00', $planner->returnEnd($carpool, Role::IDENTIFIED));
    }

    /**
     * Nothing to measure with — geocoding switched off — is the fallback,
     * never an error.
     */
    public function testWithoutGeocodingTheTravelIsUnknownAndNothingIsSent(): void
    {
        $sent = [];
        $planner = new DeparturePlanner(
            null,
            null,
            new RoutingService('', static function (string $url) use (&$sent): ?string {
                $sent[] = $url;

                return null;
            })
        );

        $this->assertNull($planner->travelMinutes(self::carpool([]), 'Parking des locaux, Wavre', 7));
        $this->assertSame([], $sent);
    }

    /**
     * @param list<CarpoolEvent> $events
     */
    private static function carpool(array $events): Carpool
    {
        return new Carpool(
            1,
            'Plaine de Basse-Wavre',
            new GeoPoint(50.71, 4.61),
            false,
            self::DAY,
            self::RETURN_DAY,
            null,
            null,
            $events
        );
    }
}
