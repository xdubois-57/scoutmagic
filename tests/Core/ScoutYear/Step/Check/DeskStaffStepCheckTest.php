<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\Module\SectionResponsableProvider;
use Core\ScoutYear\Step\Check\DeskStaffStepCheck;
use Core\ScoutYear\Step\StepCheck;
use PHPUnit\Framework\TestCase;

/**
 * Step 4 (chantier « Année scoute » §2.4): after the target year's import,
 * each section checked but the Staff d'U, its animateurs and, with the
 * Trombinoscope hook, its responsable for that year.
 */
class DeskStaffStepCheckTest extends TestCase
{
    private function sections(): SectionService
    {
        $sections = $this->createStub(SectionService::class);
        $sections->method('getSectionHeadcountsForYear')->willReturn([
            CheckFixtures::section(1, 'Baladins', chiefs: 6),
            CheckFixtures::section(2, 'Pionniers', chiefs: 4),
            CheckFixtures::section(3, 'Éclaireurs', chiefs: 0, animes: 18),
            CheckFixtures::section(4, 'Intendance', chiefs: 0, animes: 0),
            CheckFixtures::section(9, "Staff d'U", chiefs: 3, animes: 0, unitStaff: true),
        ]);

        return $sections;
    }

    private function check(?SectionResponsableProvider $responsables, bool $imported = true): StepCheck
    {
        return (new DeskStaffStepCheck($this->sections(), $responsables))->check(CheckFixtures::context($imported));
    }

    public function testBeforeTheImportOnlyTheWaitingMessage(): void
    {
        $check = $this->check(null, imported: false);

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details);
    }

    public function testASectionWithAnimesAndNoAnimateurIsToCheck(): void
    {
        $check = $this->check(null);

        $this->assertSame(['Éclaireurs : aucun animateur'], $check->pointsToCheck);
        // An intendant-only section has nobody to supervise: not a gap.
        $this->assertNotContains('Intendance : aucun animateur', $check->pointsToCheck);
    }

    public function testWithoutTrombinoscopeTheResponsableIsNotVerifiable(): void
    {
        $check = $this->check(null);

        $this->assertFalse($check->details['responsable_checked']);
        $this->assertSame([null, null, null, null], array_column($check->details['sections'], 'responsable'));
        foreach ($check->pointsToCheck as $point) {
            $this->assertStringNotContainsString('responsable', $point);
        }
    }

    public function testWithTrombinoscopeASectionWithoutResponsableIsToCheck(): void
    {
        $hook = $this->createMock(SectionResponsableProvider::class);
        $hook->expects($this->once())
            ->method('getResponsables')
            ->with([1, 2, 3, 4], CheckFixtures::TARGET_ID)
            ->willReturn([1 => CheckFixtures::profile(51, 5, 'Camille', 'Leroy'), 2 => null, 3 => null, 4 => null]);

        $check = $this->check($hook);

        $this->assertSame(['Pionniers : aucun responsable', 'Éclaireurs : aucun animateur'], $check->pointsToCheck);
        $this->assertTrue($check->details['responsable_checked']);
        $this->assertSame(
            ['name' => 'Camille Leroy', 'url' => '/admin/members/51'],
            $check->details['sections'][0]['responsable']
        );
    }

    public function testTheUnitStaffIsLeftOut(): void
    {
        $labels = array_column($this->check(null)->details['sections'], 'label');

        $this->assertNotContains("Staff d'U", $labels);
        $this->assertSame(['Baladins', 'Pionniers', 'Éclaireurs', 'Intendance'], $labels);
    }
}
