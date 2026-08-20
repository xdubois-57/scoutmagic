<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Service\ScoutYearEventCountService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * Api\ScoutYearEventCountProvider — what the "Année scoute" workflow reads
 * to decide whether a scout year's éphémérides have been encoded yet.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ScoutYearEventCountServiceTest extends TestCase
{
    private \PDO $pdo;
    private ScoutYearEventCountService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $this->service = new ScoutYearEventCountService(new CalendarEventRepository($this->pdo));

        $this->pdo->exec("INSERT INTO calendar_calendars (id, name, visibility) VALUES (1, 'Unité', 'public')");
        // A second calendar, deliberately not public: the question is "has
        // anything been encoded", not "what may this visitor see".
        $this->pdo->exec("INSERT INTO calendar_calendars (id, name, visibility) VALUES (2, 'Staff', 'chief')");
    }

    private function givenEvent(int $calendarId, string $startDate): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO calendar_events (calendar_id, title, start_date) VALUES (?, ?, ?)');
        $stmt->execute([$calendarId, 'Réunion', $startDate]);
    }

    public function testCountsNothingOnAnEmptyCalendar(): void
    {
        $this->assertSame(0, $this->service->countEventsBetween('2026-09-01', '2027-08-31'));
    }

    public function testCountsEveryCalendarWhateverItsVisibility(): void
    {
        $this->givenEvent(1, '2026-10-04');
        $this->givenEvent(2, '2026-11-08');

        $this->assertSame(2, $this->service->countEventsBetween('2026-09-01', '2027-08-31'));
    }

    public function testBoundsAreInclusive(): void
    {
        $this->givenEvent(1, '2026-09-01');
        $this->givenEvent(1, '2027-08-31');

        $this->assertSame(2, $this->service->countEventsBetween('2026-09-01', '2027-08-31'));
    }

    /**
     * The whole point of the step is to look at one year: last year's
     * calendar must not make next year's look encoded.
     */
    public function testEventsOutsideTheRangeAreIgnored(): void
    {
        $this->givenEvent(1, '2026-08-31');
        $this->givenEvent(1, '2027-09-01');

        $this->assertSame(0, $this->service->countEventsBetween('2026-09-01', '2027-08-31'));
    }

    /**
     * A camp that starts in August and runs past the year's end belongs to
     * the year it starts in — the year whose éphémérides were being encoded
     * when someone created it.
     */
    public function testAnEventIsCountedByItsStartDate(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO calendar_events (calendar_id, title, start_date, end_date) VALUES (?, ?, ?, ?)');
        $stmt->execute([1, 'Camp', '2027-08-25', '2027-09-04']);

        $this->assertSame(1, $this->service->countEventsBetween('2026-09-01', '2027-08-31'));
        $this->assertSame(0, $this->service->countEventsBetween('2027-09-01', '2028-08-31'));
    }
}
