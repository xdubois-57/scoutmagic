<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * Api\SectionEventLookupInterface — the contract `presences` builds an
 * attendance sheet on.
 *
 * Its whole value is the line it draws between « an evening this section
 * held » and « an evening on the Animateurs calendar », which
 * findEventsInWindow() deliberately does not draw. So that is what these
 * tests hold, from both directions.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionEventLookupTest extends TestCase
{
    private \PDO $pdo;
    private CalendarService $service;
    private int $sectionA;
    private int $sectionB;
    private int $calendarA;
    private int $calendarB;
    private int $supplementary;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->service = new CalendarService(
            new CalendarRepository($this->pdo, $encryption),
            new CalendarEventRepository($this->pdo),
            new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo)),
            new CalendarUnitFeedTokenRepository($this->pdo, $encryption)
        );

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();
        $this->sectionA = $this->createSection($branchId, 'LOU1');
        $this->sectionB = $this->createSection($branchId, 'LOU2');

        $this->calendarA = $this->createSectionCalendar($this->sectionA);
        $this->calendarB = $this->createSectionCalendar($this->sectionB);
        $this->pdo->exec(
            "INSERT INTO calendar_calendars (section_id, name, is_default, visibility)
             VALUES (NULL, 'Animateurs', 1, 'chief')"
        );
        $this->supplementary = (int) $this->pdo->lastInsertId();
    }

    public function testASectionsWindowHoldsItsOwnEveningsOnly(): void
    {
        $mine = $this->createEvent($this->calendarA, 'Réunion', '2026-09-13');
        $this->createEvent($this->calendarB, 'Réunion', '2026-09-13');
        $this->createEvent($this->supplementary, 'Réunion de staff', '2026-09-13');

        $events = $this->service->findSectionEventsInWindow(
            $this->sectionA,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30')
        );

        $this->assertCount(1, $events);
        $this->assertSame($mine, $events[0]->id);
        $this->assertSame($this->sectionA, $events[0]->sectionId);
        $this->assertSame('14:00', $events[0]->startTime);
    }

    public function testTheWindowIsClosedAtBothEnds(): void
    {
        // The events that must be KEPT sit exactly on the two bounds, and
        // the ones that must be dropped exactly one day outside: a window
        // written with > and < instead of >= and <= fails here, which is
        // the whole claim of this test's name.
        $this->createEvent($this->calendarA, 'La veille', '2026-08-31');
        $first = $this->createEvent($this->calendarA, 'Premier jour', '2026-09-01');
        $last = $this->createEvent($this->calendarA, 'Dernier jour', '2026-09-30');
        $this->createEvent($this->calendarA, 'Le lendemain', '2026-10-01');

        $events = $this->service->findSectionEventsInWindow(
            $this->sectionA,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30')
        );

        $this->assertSame([$first, $last], array_map(static fn ($e): int => $e->id, $events));
    }

    public function testASingleDayEventReportsItsStartDateAsItsEnd(): void
    {
        $this->createEvent($this->calendarA, 'Réunion', '2026-09-13');

        $event = $this->service->findSectionEventsInWindow(
            $this->sectionA,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30')
        )[0];

        $this->assertSame('2026-09-13', $event->startDate);
        $this->assertSame('2026-09-13', $event->endDate);
    }

    public function testASectionWithNoCalendarAnswersEmpty(): void
    {
        $branchId = (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();
        $orphan = $this->createSection($branchId, 'LOU3');

        $this->assertSame([], $this->service->findSectionEventsInWindow(
            $orphan,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30')
        ));
    }

    public function testOneEventCarriesTheSectionItBelongsTo(): void
    {
        $id = $this->createEvent($this->calendarB, 'Réunion', '2026-09-13');

        $event = $this->service->findSectionEvent($id);

        $this->assertNotNull($event);
        $this->assertSame($this->sectionB, $event->sectionId);
    }

    public function testAnEventOnASupplementaryCalendarAnswersLikeAMissingOne(): void
    {
        $id = $this->createEvent($this->supplementary, 'Réunion de staff', '2026-09-13');

        $this->assertNull($this->service->findSectionEvent($id));
        $this->assertNull($this->service->findSectionEvent(999_999));
    }

    public function testSeveralIdsComeBackKeyedByIdWithTheUnresolvableOnesDropped(): void
    {
        $a = $this->createEvent($this->calendarA, 'Réunion', '2026-09-13');
        $b = $this->createEvent($this->calendarB, 'Réunion', '2026-09-20');
        $staff = $this->createEvent($this->supplementary, 'Réunion de staff', '2026-09-14');

        $events = $this->service->findSectionEvents([$a, $b, $staff, 999_999]);

        $this->assertSame([$a, $b], array_keys($events));
        $this->assertSame($this->sectionA, $events[$a]->sectionId);
        $this->assertSame($this->sectionB, $events[$b]->sectionId);
    }

    public function testAnEmptyIdListAsksTheDatabaseNothing(): void
    {
        $this->assertSame([], $this->service->findSectionEvents([]));
    }

    private function createSection(int $branchId, string $deskCode): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSectionCalendar(int $sectionId): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO calendar_calendars (section_id, visibility) VALUES (?, 'public')");
        $stmt->execute([$sectionId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createEvent(int $calendarId, string $title, string $startDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_events (calendar_id, title, start_date, start_time) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$calendarId, $title, $startDate, '14:00']);

        return (int) $this->pdo->lastInsertId();
    }
}
