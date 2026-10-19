<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Config\ScoutYearService;
use Core\Member\SectionService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoYear;
use Core\Photo\PhotoOrigin;
use Core\ScoutYear\Step\Check\TrombinoscopeStepCheck;
use Core\ScoutYear\Step\StepCheck;
use PHPUnit\Framework\TestCase;

/**
 * Step 12 (chantier « Année scoute » §2.7): every animateur of the target
 * year, per section checked (the Staff d'U included), with a photo of that
 * year, one carried over, or none — the last two named and to check.
 */
class TrombinoscopeStepCheckTest extends TestCase
{
    private function check(bool $imported = true): StepCheck
    {
        $camille = CheckFixtures::profile(101, 1, 'Camille', 'Leroy');
        $hugo = CheckFixtures::profile(102, 2, 'Hugo', 'Petit');
        $max = CheckFixtures::profile(103, 3, 'Max', 'Collin');

        $sections = $this->createStub(SectionService::class);
        $sections->method('getSectionHeadcountsForYear')->willReturn([
            CheckFixtures::section(1, 'Baladins'),
            CheckFixtures::section(2, 'Louveteaux'),
            CheckFixtures::section(9, "Staff d'U", unitStaff: true),
        ]);
        // Max animates two sections: counted in both rows, named once.
        $sections->method('getStaffForSections')->willReturn([1 => [$camille, $max], 2 => [$hugo], 9 => [$max]]);

        $photos = $this->createMock(MemberPhotoRepository::class);
        $photos->expects($imported ? $this->once() : $this->never())
            ->method('findPhotoYears')
            ->willReturn([
                1 => new MemberPhotoYear(1, PhotoOrigin::Exact, CheckFixtures::TARGET_ID, 1),
                2 => new MemberPhotoYear(2, PhotoOrigin::Earlier, 8, 2),
                3 => new MemberPhotoYear(3, PhotoOrigin::None, null, null),
            ]);

        $years = $this->createStub(ScoutYearService::class);
        $years->method('findById')->willReturn(['id' => 8, 'label' => '2024-2025', 'start_date' => '2024-09-01', 'end_date' => '2025-08-31']);

        return (new TrombinoscopeStepCheck($sections, $photos, $years))->check(CheckFixtures::context($imported));
    }

    public function testBeforeTheImportOnlyTheWaitingMessage(): void
    {
        $check = $this->check(imported: false);

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details);
    }

    public function testMissingAndInheritedPhotosAreCountedPerSectionAndToCheck(): void
    {
        $check = $this->check();

        $this->assertSame(['1 animateur sans photo', '1 photo héritée à vérifier'], $check->pointsToCheck);
        $this->assertSame([
            ['label' => 'Baladins', 'total' => 2, 'exact' => 1, 'earlier' => 0, 'none' => 1],
            ['label' => 'Louveteaux', 'total' => 1, 'exact' => 0, 'earlier' => 1, 'none' => 0],
            ['label' => "Staff d'U", 'total' => 1, 'exact' => 0, 'earlier' => 0, 'none' => 1],
        ], $check->details['sections']);
    }

    public function testThePeopleToCorrectAreNamedWithTheirSheet(): void
    {
        $this->assertSame([
            ['name' => 'Max Collin', 'section' => 'Baladins', 'url' => '/admin/members/103', 'origin' => 'none', 'photo_year' => null],
            ['name' => 'Hugo Petit', 'section' => 'Louveteaux', 'url' => '/admin/members/102', 'origin' => 'earlier', 'photo_year' => '2024-2025'],
        ], $this->check()->details['people']);
    }
}
