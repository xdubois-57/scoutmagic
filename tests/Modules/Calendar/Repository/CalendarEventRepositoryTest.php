<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Repository;

use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarEventRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarEventRepository $repository;
    private int $calendarId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarEventRepository($this->pdo);

        $calendarRepo = new CalendarRepository($this->pdo);
        $this->calendarId = $calendarRepo->createSupplementaryCalendar('Animateurs', true, 'public', 'tok');
    }

    public function testCreateAndFindById(): void
    {
        $id = $this->repository->create($this->calendarId, 'Réunion', '2026-03-15', null, '14:00:00', '16:00:00', 'Local', 'Desc', 5);

        $event = $this->repository->findById($id);

        $this->assertNotNull($event);
        $this->assertSame('Réunion', $event->title);
        $this->assertSame('2026-03-15', $event->startDate);
        $this->assertSame('14:00:00', $event->startTime);
        $this->assertSame('Local', $event->location);
        $this->assertSame('Desc', $event->description);
        $this->assertSame(5, $event->createdBy);
        $this->assertSame(0, $event->sequence);
    }

    public function testFindByIdReturnsNullForUnknown(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testUpdateBumpsSequenceAndChangesFields(): void
    {
        $id = $this->repository->create($this->calendarId, 'Old', '2026-03-15', null, null, null, null, null, null);

        $this->repository->update($id, $this->calendarId, 'New', '2026-03-16', null, '10:00:00', '12:00:00', 'Loc', 'Desc');

        $event = $this->repository->findById($id);
        $this->assertSame('New', $event->title);
        $this->assertSame('2026-03-16', $event->startDate);
        $this->assertSame(1, $event->sequence);
    }

    public function testUpdateTwiceIncrementsSequenceEachTime(): void
    {
        $id = $this->repository->create($this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, null);

        $this->repository->update($id, $this->calendarId, 'Title', '2026-03-15', null, null, null, null, null);
        $this->repository->update($id, $this->calendarId, 'Title', '2026-03-15', null, null, null, null, null);

        $this->assertSame(2, $this->repository->findById($id)->sequence);
    }

    public function testDeleteRemovesEvent(): void
    {
        $id = $this->repository->create($this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, null);

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testCalendarHasEventsReflectsState(): void
    {
        $this->assertFalse($this->repository->calendarHasEvents($this->calendarId));

        $this->repository->create($this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, null);

        $this->assertTrue($this->repository->calendarHasEvents($this->calendarId));
    }

    public function testFindByCalendarIdsInRangeIncludesOverlappingMultiDayEvent(): void
    {
        // Event spans March 28-April 2; querying March range must include it.
        $this->repository->create($this->calendarId, 'Camp', '2026-03-28', '2026-04-02', null, null, null, null, null);

        $marchEvents = $this->repository->findByCalendarIdsInRange([$this->calendarId], '2026-03-01', '2026-03-31');
        $aprilEvents = $this->repository->findByCalendarIdsInRange([$this->calendarId], '2026-04-01', '2026-04-30');

        $this->assertCount(1, $marchEvents);
        $this->assertCount(1, $aprilEvents);
    }

    public function testFindByCalendarIdsInRangeExcludesEventOutsideRange(): void
    {
        $this->repository->create($this->calendarId, 'Réunion', '2026-05-01', null, null, null, null, null, null);

        $events = $this->repository->findByCalendarIdsInRange([$this->calendarId], '2026-03-01', '2026-03-31');

        $this->assertEmpty($events);
    }

    public function testFindByCalendarIdsInRangeReturnsEmptyForEmptyIdList(): void
    {
        $this->assertSame([], $this->repository->findByCalendarIdsInRange([], '2026-01-01', '2026-12-31'));
    }

    public function testFindUpcomingExcludesPastEvents(): void
    {
        $this->repository->create($this->calendarId, 'Past', '2020-01-01', null, null, null, null, null, null);
        $this->repository->create($this->calendarId, 'Future', '2099-01-01', null, null, null, null, null, null);

        $upcoming = $this->repository->findUpcoming([$this->calendarId], '2026-01-01', 10);

        $this->assertCount(1, $upcoming);
        $this->assertSame('Future', $upcoming[0]->title);
    }

    public function testFindUpcomingRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repository->create($this->calendarId, "Event {$i}", '2099-01-0' . ($i + 1), null, null, null, null, null, null);
        }

        $upcoming = $this->repository->findUpcoming([$this->calendarId], '2026-01-01', 3);

        $this->assertCount(3, $upcoming);
    }

    public function testFindUpcomingOrdersByStartDateAscending(): void
    {
        $this->repository->create($this->calendarId, 'Later', '2099-06-01', null, null, null, null, null, null);
        $this->repository->create($this->calendarId, 'Sooner', '2099-01-01', null, null, null, null, null, null);

        $upcoming = $this->repository->findUpcoming([$this->calendarId], '2026-01-01', 10);

        $this->assertSame('Sooner', $upcoming[0]->title);
        $this->assertSame('Later', $upcoming[1]->title);
    }

    public function testFindByCalendarIdsReturnsAllRegardlessOfDate(): void
    {
        $this->repository->create($this->calendarId, 'Past', '2020-01-01', null, null, null, null, null, null);
        $this->repository->create($this->calendarId, 'Future', '2099-01-01', null, null, null, null, null, null);

        $events = $this->repository->findByCalendarIds([$this->calendarId]);

        $this->assertCount(2, $events);
    }

    public function testFindByCalendarIdReturnsOnlyThatCalendarsEvents(): void
    {
        $calendarRepo = new CalendarRepository($this->pdo);
        $otherCalendarId = $calendarRepo->createSupplementaryCalendar('Other', false, 'public', 'tok2');

        $this->repository->create($this->calendarId, 'Mine', '2026-01-01', null, null, null, null, null, null);
        $this->repository->create($otherCalendarId, 'Other', '2026-01-01', null, null, null, null, null, null);

        $events = $this->repository->findByCalendarId($this->calendarId);

        $this->assertCount(1, $events);
        $this->assertSame('Mine', $events[0]->title);
    }
}
