<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Modules\InboundMail\Service\ManualRefreshService;
use Modules\InboundMail\Service\SyncOutcome;
use Modules\InboundMail\Service\SyncReport;
use PHPUnit\Framework\TestCase;

/**
 * What « Rafraîchir maintenant » says is what the boxes did (#1325): the
 * request succeeding was announced in green as « 0 message(s) lu(s) »,
 * above a box the same page showed in error.
 */
class ManualRefreshSummaryTest extends TestCase
{
    public function testEveryBoxReadIsASuccessWithItsCounts(): void
    {
        $summary = ManualRefreshService::summarise($this->report(
            SyncOutcome::succeeded(4, 2),
            SyncOutcome::succeeded(1, 1)
        ));

        $this->assertTrue($summary['ok']);
        $this->assertSame('success', $summary['level']);
        $this->assertSame('Relève réussie pour 2 boîte(s) : 5 message(s) lu(s), 3 conservé(s).', $summary['message']);
    }

    public function testTheOneBoxFailingIsAFailureAndNotAGreenZero(): void
    {
        $summary = ManualRefreshService::summarise($this->report(SyncOutcome::failed('Le serveur…')));

        $this->assertFalse($summary['ok']);
        $this->assertSame('error', $summary['level']);
        $this->assertStringContainsString('La relève a échoué pour la boîte', $summary['message']);
        $this->assertStringNotContainsString('message(s) lu(s)', $summary['message']);
    }

    public function testEveryBoxFailingSaysHowMany(): void
    {
        $summary = ManualRefreshService::summarise($this->report(
            SyncOutcome::failed('a'),
            SyncOutcome::failed('b')
        ));

        $this->assertSame('error', $summary['level']);
        $this->assertStringContainsString('les 2 boîtes', $summary['message']);
    }

    public function testOneBoxOfTwoFailingIsAPartialRelèveWithTheOthersCounts(): void
    {
        $summary = ManualRefreshService::summarise($this->report(
            SyncOutcome::succeeded(3, 1),
            SyncOutcome::failed('a')
        ));

        $this->assertFalse($summary['ok']);
        $this->assertSame('warning', $summary['level']);
        $this->assertStringContainsString('Relève partielle : 1 boîte(s) sur 2 en échec', $summary['message']);
        $this->assertStringContainsString('3 message(s) lu(s), 1 conservé(s)', $summary['message']);
    }

    public function testABoxSkippedForWantOfCredentialsIsNeitherAFailureNorARelève(): void
    {
        $summary = ManualRefreshService::summarise($this->report(
            SyncOutcome::succeeded(2, 2),
            SyncOutcome::skipped('Identifiants introuvables.')
        ));

        $this->assertSame('success', $summary['level']);
        $this->assertStringContainsString('pour 1 boîte(s)', $summary['message']);
    }

    public function testNothingToReadIsSaidRatherThanCalledASuccess(): void
    {
        $summary = ManualRefreshService::summarise(new SyncReport());

        $this->assertFalse($summary['ok']);
        $this->assertSame('warning', $summary['level']);
        $this->assertStringContainsString('Aucune boîte n\'a été relevée', $summary['message']);
    }

    private function report(SyncOutcome ...$outcomes): SyncReport
    {
        $report = new SyncReport();
        foreach ($outcomes as $i => $outcome) {
            $report->add($i + 1, $outcome);
        }

        return $report;
    }
}
