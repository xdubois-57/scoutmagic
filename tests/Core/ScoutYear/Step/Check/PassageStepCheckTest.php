<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\Member\SectionService;
use Core\ScoutYear\Step\Check\PassageStepCheck;
use Core\ScoutYear\Step\StepCheck;
use Modules\Registration\Api\PassageWithoutDestination;
use Modules\Registration\Api\ScoutYearPreparationProvider;
use Modules\Registration\Api\ScoutYearRegistrationReviewProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 2: passages expected, animés without an arrival section named and
 * counted as points to check, « aucun passage » when there is none.
 */
class PassageStepCheckTest extends TestCase
{
    /**
     * @param list<PassageWithoutDestination> $people
     */
    private function check(int $total, int $unassigned, array $people = []): StepCheck
    {
        $preparation = $this->createStub(ScoutYearPreparationProvider::class);
        $preparation->method('countPassages')->willReturn($total);
        $preparation->method('countUnassignedPassages')->willReturn($unassigned);

        $review = $this->createMock(ScoutYearRegistrationReviewProvider::class);
        $review->expects($unassigned > 0 ? $this->once() : $this->never())
            ->method('passagesWithoutDestination')
            ->with(CheckFixtures::TARGET_ID)
            ->willReturn($people);

        $sections = $this->createStub(SectionService::class);
        $sections->method('findByIds')->willReturn([3 => CheckFixtures::sectionRow(3, 'Louveteaux')]);

        return (new PassageStepCheck($preparation, $review, $sections))->check(CheckFixtures::context(imported: false));
    }

    public function testZeroPassageIsNoPointAndIsSaidAsSuch(): void
    {
        $check = $this->check(0, 0);

        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame(['passage_count' => 0, 'unassigned_count' => 0, 'unassigned' => []], $check->details);
    }

    public function testAnAnimeWithoutArrivalSectionIsNamedAndToCheck(): void
    {
        $check = $this->check(9, 1, [new PassageWithoutDestination(4, 'Léa Martin', 3, 'Louveteau 4e année', 2)]);

        $this->assertSame(["1 animé sans section d'arrivée"], $check->pointsToCheck);
        $this->assertSame(
            [['name' => 'Léa Martin', 'section' => 'Louveteaux', 'branch_year' => 'Louveteau 4e année']],
            $check->details['unassigned']
        );
        $this->assertSame('Ouvrir Passage', $check->primaryAction?->label);
    }

    public function testSeveralAnimesAreCountedInThePlural(): void
    {
        $this->assertSame(["3 animés sans section d'arrivée"], $this->check(9, 3)->pointsToCheck);
    }
}
