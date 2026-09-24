<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * The calendar API as the carpool module reads it (docs/chantiers/
 * covoiturage.md, IT-03): an event's place and section in EventSummary, and
 * a search over upcoming events the reader may see.
 */
final class CalendarEventSearchTest extends TestCase
{
    private \PDO $pdo;
    private CalendarService $calendars;
    private CalendarEventRepository $events;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->events = new CalendarEventRepository($this->pdo);
        $this->calendars = new CalendarService(
            new CalendarRepository($this->pdo, $encryption),
            $this->events,
            new SectionService(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo)),
            new CalendarUnitFeedTokenRepository($this->pdo, $encryption)
        );
    }

    private function sectionCalendarId(string $deskCode, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, 10]);
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, (int) $this->pdo->lastInsertId(), $name]);
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->calendars->ensureSectionCalendars();

        return (int) $this->pdo->query("SELECT id FROM calendar_calendars WHERE section_id = {$sectionId}")->fetchColumn();
    }

    private function inDays(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public function testTheSummaryCarriesThePlaceAndTheSection(): void
    {
        $calendarId = $this->sectionCalendarId('LOU01', 'Louveteaux');
        $id = $this->events->create($calendarId, 'Week-end de section', $this->inDays(10), null, null, null, 'Gîte de Han-sur-Lesse', null, null);

        $summary = $this->calendars->findEventById($id, Role::PUBLIC);

        $this->assertNotNull($summary);
        $this->assertSame('Gîte de Han-sur-Lesse', $summary->location);
        $this->assertSame('Louveteaux', $summary->sectionName);
        $this->assertNotNull($summary->sectionId);
    }

    public function testAnEventWithNoPlaceSaysNothingRatherThanAnEmptyString(): void
    {
        $calendar = $this->calendars->addCalendar('Animateurs bis', 'public');
        $id = $this->events->create($calendar->id, 'Réunion', $this->inDays(3), null, null, null, '  ', null, null);

        $summary = $this->calendars->findEventById($id, Role::PUBLIC);

        $this->assertNull($summary?->location);
        // A supplementary calendar belongs to no section.
        $this->assertNull($summary?->sectionId);
    }

    public function testTheWindowLookupCarriesThePlaceToo(): void
    {
        $calendarId = $this->sectionCalendarId('BAL01', 'Baladins');
        $this->events->create($calendarId, 'Fête', $this->inDays(5), null, null, null, 'Plaine de Basse-Wavre', null, null);

        $found = $this->calendars->findEventsInWindow(
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('+1 month'),
            null,
            Role::PUBLIC
        );

        $this->assertSame('Plaine de Basse-Wavre', $found[0]->location ?? null);
    }

    public function testTheSearchMatchesTitleCalendarAndSectionIgnoringAccents(): void
    {
        $louveteaux = $this->sectionCalendarId('LOU01', 'Louveteaux');
        $eclaireurs = $this->sectionCalendarId('ECL01', 'Éclaireurs');
        $this->events->create($louveteaux, "Fête d'unité", $this->inDays(20), null, null, null, null, null, null);
        $this->events->create($eclaireurs, "Fête d'unité", $this->inDays(20), null, null, null, null, null, null);
        $this->events->create($eclaireurs, 'Hike', $this->inDays(30), null, null, null, null, null, null);

        $this->assertCount(2, $this->calendars->searchUpcomingEvents('fete', Role::PUBLIC));
        $byCalendar = $this->calendars->searchUpcomingEvents('eclaireurs', Role::PUBLIC);
        $this->assertSame(["Fête d'unité", 'Hike'], array_map(fn($e) => $e->title, $byCalendar));
        // Every word is required: « fête » AND « éclaireurs ».
        $both = $this->calendars->searchUpcomingEvents('FÊTE eclai', Role::PUBLIC);
        $this->assertCount(1, $both);
        $this->assertSame('Éclaireurs', $both[0]->sectionName);
    }

    public function testThePastIsNeverOfferedButAWeekendUnderWayIs(): void
    {
        $calendarId = $this->sectionCalendarId('LOU01', 'Louveteaux');
        $this->events->create($calendarId, 'Camp passé', $this->inDays(-10), $this->inDays(-8), null, null, null, null, null);
        $this->events->create($calendarId, 'Week-end en cours', $this->inDays(-1), $this->inDays(1), null, null, null, null, null);

        $titles = array_map(fn($e) => $e->title, $this->calendars->searchUpcomingEvents('', Role::PUBLIC));

        $this->assertSame(['Week-end en cours'], $titles);
    }

    public function testOnlyCalendarsTheReaderMaySeeAreSearched(): void
    {
        $chiefs = $this->calendars->addCalendar('Réservé', 'chief');
        $admins = $this->calendars->addCalendar('Staff', 'admin');
        $this->events->create($chiefs->id, 'Réunion de staff', $this->inDays(4), null, null, null, null, null, null);
        $this->events->create($admins->id, 'Réunion du staff d\'U', $this->inDays(4), null, null, null, null, null, null);

        $this->assertSame([], $this->calendars->searchUpcomingEvents('réunion', Role::IDENTIFIED));
        $this->assertCount(1, $this->calendars->searchUpcomingEvents('réunion', Role::CHIEF));
        $this->assertCount(2, $this->calendars->searchUpcomingEvents('réunion', Role::ADMIN));
    }

    public function testTheSearchIsBoundedAndSoonestFirst(): void
    {
        $calendar = $this->calendars->addCalendar('Tout', 'public');
        foreach ([9, 3, 6] as $days) {
            $this->events->create($calendar->id, "Sortie J+{$days}", $this->inDays($days), null, null, null, null, null, null);
        }

        $titles = array_map(fn($e) => $e->title, $this->calendars->searchUpcomingEvents('sortie', Role::PUBLIC, 2));

        $this->assertSame(['Sortie J+3', 'Sortie J+6'], $titles);
    }
}
