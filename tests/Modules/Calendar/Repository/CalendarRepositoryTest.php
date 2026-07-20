<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Repository;

use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CalendarRepository $repository;
    private int $sectionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->repository = new CalendarRepository($this->pdo);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 10)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('BAL01', {$branchId}, 'Renards')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    public function testCreateSectionCalendarAndFindBySectionId(): void
    {
        $id = $this->repository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);

        $calendar = $this->repository->findBySectionId($this->sectionId);

        $this->assertNotNull($calendar);
        $this->assertSame($id, $calendar->id);
        $this->assertSame($this->sectionId, $calendar->sectionId);
        $this->assertTrue($calendar->isSectionCalendar());
        $this->assertSame(Calendar::VISIBILITY_PUBLIC, $calendar->visibility);
        $this->assertNull($calendar->icsToken);
        $this->assertFalse($calendar->isDefault);
    }

    public function testFindBySectionIdReturnsNullWhenNone(): void
    {
        $this->assertNull($this->repository->findBySectionId(9999));
    }

    public function testCreateSupplementaryCalendarAndFindByIcsToken(): void
    {
        $id = $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok123');

        $calendar = $this->repository->findByIcsToken('tok123');

        $this->assertNotNull($calendar);
        $this->assertSame($id, $calendar->id);
        $this->assertNull($calendar->sectionId);
        $this->assertFalse($calendar->isSectionCalendar());
        $this->assertSame('Animateurs', $calendar->name);
        $this->assertTrue($calendar->isDefault);
        $this->assertSame('tok123', $calendar->icsToken);
    }

    public function testFindByIcsTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->repository->findByIcsToken('nope'));
    }

    public function testFindDefaultCalendar(): void
    {
        $this->repository->createSupplementaryCalendar('Custom', false, Calendar::VISIBILITY_PUBLIC, 'tok-a');
        $defaultId = $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok-b');

        $default = $this->repository->findDefaultCalendar();

        $this->assertNotNull($default);
        $this->assertSame($defaultId, $default->id);
    }

    public function testFindDefaultCalendarReturnsNullWhenNoneExists(): void
    {
        $this->assertNull($this->repository->findDefaultCalendar());
    }

    public function testFindSectionCalendarsExcludesSupplementary(): void
    {
        $this->repository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');

        $sectionCalendars = $this->repository->findSectionCalendars();

        $this->assertCount(1, $sectionCalendars);
        $this->assertTrue($sectionCalendars[0]->isSectionCalendar());
    }

    public function testFindSupplementaryCalendarsExcludesSectionCalendars(): void
    {
        $this->repository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');

        $supplementary = $this->repository->findSupplementaryCalendars();

        $this->assertCount(1, $supplementary);
        $this->assertFalse($supplementary[0]->isSectionCalendar());
    }

    public function testUpdateVisibility(): void
    {
        $id = $this->repository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);

        $this->repository->updateVisibility($id, Calendar::VISIBILITY_CHIEF);

        $calendar = $this->repository->findById($id);
        $this->assertSame(Calendar::VISIBILITY_CHIEF, $calendar->visibility);
    }

    public function testUpdateIcsToken(): void
    {
        $id = $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'old-token');

        $this->repository->updateIcsToken($id, 'new-token');

        $calendar = $this->repository->findById($id);
        $this->assertSame('new-token', $calendar->icsToken);
        $this->assertNull($this->repository->findByIcsToken('old-token'));
    }

    public function testDeleteRemovesCalendar(): void
    {
        $id = $this->repository->createSupplementaryCalendar('Custom', false, Calendar::VISIBILITY_PUBLIC, 'tok');

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }

    public function testFindAllReturnsEverything(): void
    {
        $this->repository->createSectionCalendar($this->sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->repository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');

        $this->assertCount(2, $this->repository->findAll());
    }
}
