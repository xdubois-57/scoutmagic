<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Badge\Badge;
use Core\Badge\BadgeHolder;
use Core\Badge\BadgeHolders;
use Core\Badge\BadgeHolderService;
use Core\Badge\BadgeRepository;
use Core\Badge\BadgeYearAggregate;
use Core\Badge\TransversalBadgeGap;
use Core\Member\SectionService;
use Core\ScoutYear\Step\Check\BadgesStepCheck;
use Core\ScoutYear\Step\StepCheck;
use PHPUnit\Framework\TestCase;

/**
 * Step 11 (chantier « Année scoute » §2.6): an informative view of the
 * target year's badges. A referent badge is filed under the Staff d'U and
 * never makes the section it names look incomplete.
 */
class BadgesStepCheckTest extends TestCase
{
    private Badge $nurse;
    private Badge $treasurer;
    private Badge $referentBaladins;
    private Badge $referentPionniers;
    private Badge $referentHiddenSection;

    protected function setUp(): void
    {
        $this->nurse = new Badge(1, 'Infirmier·e', true, true);
        $this->treasurer = new Badge(2, 'Trésorier·e', true, true);
        $this->referentBaladins = new Badge(3, 'Référent·e Baladins', true, true, 1);
        $this->referentPionniers = new Badge(4, 'Référent·e Pionniers', true, true, 2);
        // A referent badge of a section with nobody in it this year.
        $this->referentHiddenSection = new Badge(5, 'Référent·e Ancienne', true, true, 77);
    }

    private function check(BadgeYearAggregate $aggregate, bool $imported = true): StepCheck
    {
        $sections = $this->createStub(SectionService::class);
        $sections->method('getSectionHeadcountsForYear')->willReturn([
            CheckFixtures::section(1, 'Baladins'),
            CheckFixtures::section(2, 'Pionniers'),
            CheckFixtures::section(9, "Staff d'U", unitStaff: true),
        ]);
        $sections->method('findByIds')->willReturn([9 => CheckFixtures::sectionRow(9, "Staff d'U")]);

        $holders = $this->createMock(BadgeHolderService::class);
        $holders->expects($imported ? $this->once() : $this->never())
            ->method('sectionAggregateForYear')
            ->with(CheckFixtures::TARGET_ID, [1, 2])
            ->willReturn($aggregate);

        $badges = $this->createStub(BadgeRepository::class);
        $badges->method('findAll')->willReturn([
            $this->nurse, $this->treasurer, $this->referentBaladins, $this->referentPionniers, $this->referentHiddenSection,
        ]);

        return (new BadgesStepCheck($sections, $holders, $badges))->check(CheckFixtures::context($imported));
    }

    private function worn(Badge $badge, string $name, int $memberYearId): BadgeHolders
    {
        return new BadgeHolders($badge, [new BadgeHolder($memberYearId, $name, null, null)]);
    }

    public function testBeforeTheImportOnlyTheWaitingMessage(): void
    {
        $check = $this->check(new BadgeYearAggregate([], [], [], []), imported: false);

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
    }

    public function testAReferentBadgeIsFiledUnderTheUnitStaffAndNeverASectionGap(): void
    {
        $check = $this->check(new BadgeYearAggregate(
            [
                1 => [$this->worn($this->nurse, 'Camille Leroy', 11), $this->worn($this->treasurer, 'Paul Roy', 12)],
                2 => [$this->worn($this->treasurer, 'Eva Jacob', 21)],
                9 => [$this->worn($this->referentBaladins, 'Xavier Dubois', 91)],
            ],
            [],
            [],
            [new TransversalBadgeGap($this->nurse, [1], [2])]
        ));

        $this->assertSame(['Référent·e Pionniers attribué nulle part', 'Infirmier·e absent dans 1 section'], $check->pointsToCheck);
        foreach ($check->pointsToCheck as $point) {
            $this->assertStringNotContainsString('Baladins', $point, 'the badge naming Baladins is worn by the Staff d\'U');
        }
        $this->assertSame(['Référent·e Pionniers'], $check->details['unworn']);
        $this->assertSame([['badge' => 'Infirmier·e', 'without' => ['Pionniers']]], $check->details['gaps']);
        $this->assertSame(['Baladins', 'Pionniers', "Staff d'U"], array_column($check->details['sections'], 'label'));
        $this->assertSame(
            [['name' => 'Référent·e Baladins', 'holders' => [['name' => 'Xavier Dubois', 'url' => '/admin/members/91']]]],
            $check->details['sections'][2]['badges']
        );
    }

    public function testDefaultBadgesWornNowhereAreToCheck(): void
    {
        $check = $this->check(new BadgeYearAggregate(
            [1 => [], 2 => [], 9 => [$this->worn($this->referentBaladins, 'A', 1), $this->worn($this->referentPionniers, 'B', 2)]],
            [],
            [$this->nurse, $this->treasurer],
            []
        ));

        $this->assertSame(['Infirmier·e attribué nulle part', 'Trésorier·e attribué nulle part'], $check->pointsToCheck);
    }

    public function testHoldersWithoutASectionAreShownApart(): void
    {
        $check = $this->check(new BadgeYearAggregate(
            [1 => [], 2 => [], 9 => [$this->worn($this->referentBaladins, 'A', 1), $this->worn($this->referentPionniers, 'B', 2)]],
            [$this->worn($this->treasurer, 'Sans Section', 5)],
            [],
            []
        ));

        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame('Sans section', $check->details['sections'][3]['label']);
    }
}
