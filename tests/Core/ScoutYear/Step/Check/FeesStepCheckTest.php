<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\Check\FeesStepCheck;
use Modules\Fees\Api\FeeAccuracySummary;
use Modules\Fees\Api\FeeAccuracySummaryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Step 7: the fees module's accuracy summary of the target year's import —
 * households to correct and members without an address are points to
 * check, households set aside information only.
 */
class FeesStepCheckTest extends TestCase
{
    public function testBeforeTheImportTheSummaryIsNotRead(): void
    {
        $provider = $this->createMock(FeeAccuracySummaryProvider::class);
        $provider->expects($this->never())->method('summaryForYear');

        $check = (new FeesStepCheck($provider))->check(CheckFixtures::context(imported: false));

        $this->assertSame('Le contrôle sera disponible après un import réussi de 2026-2027.', $check->pendingMessage);
        $this->assertSame([], $check->pointsToCheck);
        $this->assertArrayNotHasKey('households_checked', $check->details);
    }

    public function testTheSummaryOfTheTargetYearGivesThePoints(): void
    {
        $provider = $this->createMock(FeeAccuracySummaryProvider::class);
        $provider->expects($this->once())
            ->method('summaryForYear')
            ->with(CheckFixtures::TARGET_ID)
            ->willReturn(new FeeAccuracySummary(3, 4, 2, 48));

        $check = (new FeesStepCheck($provider))->check(CheckFixtures::context());

        $this->assertSame(['3 foyers à corriger', '4 membres non vérifiables (sans adresse)'], $check->pointsToCheck);
        $this->assertSame(48, $check->details['households_checked']);
        $this->assertSame(2, $check->details['households_set_aside']);
        $this->assertSame('/admin/fees/tarifs', $check->primaryAction?->url);
    }

    public function testHouseholdsSetAsideAloneAreNoPoint(): void
    {
        $provider = $this->createStub(FeeAccuracySummaryProvider::class);
        $provider->method('summaryForYear')->willReturn(new FeeAccuracySummary(0, 0, 5, 40));

        $this->assertSame([], (new FeesStepCheck($provider))->check(CheckFixtures::context())->pointsToCheck);
    }

    public function testWithoutTheModuleThereIsNothingToSay(): void
    {
        $check = (new FeesStepCheck(null))->check(CheckFixtures::context());

        $this->assertSame([], $check->pointsToCheck);
        $this->assertSame([], $check->details);
    }
}
