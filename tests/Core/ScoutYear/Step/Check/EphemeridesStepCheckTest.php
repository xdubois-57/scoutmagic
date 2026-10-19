<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\Check\EphemeridesStepCheck;
use Modules\Calendar\Api\ScoutYearEventCountProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 10: the events of the target year per section checked; only a
 * section with none is a point to check.
 */
class EphemeridesStepCheckTest extends TestCase
{
    private function sections(): SectionService
    {
        $sections = $this->createStub(SectionService::class);
        $sections->method('getSectionHeadcountsForYear')->willReturn([
            CheckFixtures::section(1, 'Baladins'),
            CheckFixtures::section(2, 'Éclaireurs'),
            CheckFixtures::section(3, 'Pionniers'),
            CheckFixtures::section(9, "Staff d'U", unitStaff: true),
        ]);

        return $sections;
    }

    public function testOnlyASectionWithoutAnyEventIsToCheck(): void
    {
        $calendar = $this->createMock(ScoutYearEventCountProvider::class);
        $calendar->expects($this->once())->method('countEventsBetween')->with('2026-09-01', '2027-08-31')->willReturn(30);
        $calendar->expects($this->once())
            ->method('countEventsBySectionBetween')
            ->with('2026-09-01', '2027-08-31')
            ->willReturn([1 => 14, 2 => 3]);

        $check = (new EphemeridesStepCheck($calendar, $this->sections()))->check(CheckFixtures::context());

        $this->assertSame(['Pionniers : aucun événement'], $check->pointsToCheck);
        $this->assertSame([
            ['label' => 'Baladins', 'event_count' => 14],
            ['label' => 'Éclaireurs', 'event_count' => 3],
            ['label' => 'Pionniers', 'event_count' => 0],
        ], $check->details['sections']);
        $this->assertSame(30, $check->details['event_count']);
    }

    public function testBeforeTheImportTheSectionsAreNotKnownYet(): void
    {
        $calendar = $this->createMock(ScoutYearEventCountProvider::class);
        $calendar->method('countEventsBetween')->willReturn(0);
        $calendar->expects($this->never())->method('countEventsBySectionBetween');

        $check = (new EphemeridesStepCheck($calendar, $this->sections()))->check(CheckFixtures::context(imported: false));

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame(['event_count' => 0], $check->details);
    }
}
