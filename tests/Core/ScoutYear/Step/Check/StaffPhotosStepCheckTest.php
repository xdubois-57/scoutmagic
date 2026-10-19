<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\Photo\SectionPhotoRepository;
use Core\ScoutYear\Step\Check\StaffPhotosStepCheck;
use PHPUnit\Framework\TestCase;

/**
 * Step 13: the sections checked in the target year (the Staff d'U
 * included) without a group photo of THAT year, named in one point.
 */
class StaffPhotosStepCheckTest extends TestCase
{
    private function sections(): SectionService
    {
        $sections = $this->createStub(SectionService::class);
        $sections->method('getSectionHeadcountsForYear')->willReturn([
            CheckFixtures::section(1, 'Baladins'),
            CheckFixtures::section(3, 'Éclaireurs'),
            CheckFixtures::section(4, 'Pionniers'),
            CheckFixtures::section(9, "Staff d'U", unitStaff: true),
        ]);

        return $sections;
    }

    public function testTheSectionsWithoutThisYearsPhotoAreNamed(): void
    {
        $photos = $this->createMock(SectionPhotoRepository::class);
        $photos->expects($this->once())
            ->method('findSectionIdsWithoutPhotoForYear')
            ->with([1, 3, 4, 9], CheckFixtures::TARGET_ID)
            ->willReturn([3, 4]);

        $check = (new StaffPhotosStepCheck($this->sections(), $photos))->check(CheckFixtures::context());

        $this->assertSame(['Éclaireurs, Pionniers : pas de photo 2026-2027'], $check->pointsToCheck);
        $this->assertSame(
            ['section_count' => 4, 'with_photo_count' => 2, 'missing' => ['Éclaireurs', 'Pionniers']],
            $check->details
        );
    }

    public function testEverySectionPhotographedIsNoPoint(): void
    {
        $photos = $this->createStub(SectionPhotoRepository::class);
        $photos->method('findSectionIdsWithoutPhotoForYear')->willReturn([]);

        $this->assertSame([], (new StaffPhotosStepCheck($this->sections(), $photos))->check(CheckFixtures::context())->pointsToCheck);
    }

    public function testBeforeTheImportOnlyTheWaitingMessage(): void
    {
        $photos = $this->createMock(SectionPhotoRepository::class);
        $photos->expects($this->never())->method('findSectionIdsWithoutPhotoForYear');

        $check = (new StaffPhotosStepCheck($this->sections(), $photos))->check(CheckFixtures::context(imported: false));

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details);
    }
}
