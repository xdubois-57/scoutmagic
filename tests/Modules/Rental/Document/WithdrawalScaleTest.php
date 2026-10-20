<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Document;

use Modules\Rental\Document\WithdrawalScale;
use PHPUnit\Framework\TestCase;

/**
 * The renter's withdrawal scale (issue #977): stretches that follow each
 * other without overlap or gap, and never a past deadline offered.
 */
final class WithdrawalScaleTest extends TestCase
{
    private static function scale(string $arrival, int $days, string $today): WithdrawalScale
    {
        $scale = WithdrawalScale::of($arrival, $days, new \DateTimeImmutable($today . ' 15:30:00'));
        self::assertNotNull($scale);

        return $scale;
    }

    /** The default: free, then fifty per cent until a month before, then seventy-five. */
    public function testTheDefaultDeadlineFollowsTheFreePeriodWithTwoStretches(): void
    {
        $scale = self::scale('2027-07-01', 90, '2027-01-01');

        $this->assertSame('2027-04-02', $scale->freeUntil);
        $this->assertSame(
            'Jusqu\'au 02/04/2027 inclus, il le fait sans indemnité et les sommes versées lui sont remboursées.',
            $scale->freeSentence()
        );
        $this->assertSame(
            'du 03/04/2027 au 01/06/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 02/06/2027, soixante-quinze pour cent du prix de la location.',
            $scale->scaleSentence()
        );
    }

    /** A deadline later than one month before the stay leaves no fifty-per-cent stretch, and no gap. */
    public function testAShortDeadlineGoesStraightToTheLastStretch(): void
    {
        $scale = self::scale('2027-07-01', 10, '2027-01-01');

        $this->assertSame('2027-06-21', $scale->freeUntil);
        $this->assertSame(
            'à partir du 22/06/2027, soixante-quinze pour cent du prix de la location.',
            $scale->scaleSentence()
        );
        $this->assertStringNotContainsString('cinquante', $scale->scaleSentence());
    }

    /** The deadline falling exactly a month before: the free period ends there, the last stretch starts next day. */
    public function testADeadlineOnTheMonthBoundaryLeavesNeitherGapNorOverlap(): void
    {
        $scale = self::scale('2027-07-01', 30, '2027-01-01');

        $this->assertSame('2027-06-01', $scale->freeUntil);
        $this->assertSame(
            'à partir du 02/06/2027, soixante-quinze pour cent du prix de la location.',
            $scale->scaleSentence()
        );

        $longer = self::scale('2027-07-01', 31, '2027-01-01');
        $this->assertSame('2027-05-31', $longer->freeUntil);
        $this->assertSame(
            'du 01/06/2027 au 01/06/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 02/06/2027, soixante-quinze pour cent du prix de la location.',
            $longer->scaleSentence()
        );
    }

    /** One month before the 31st of a month is the last day of the shorter month, never an overflow. */
    public function testOneMonthBeforeClampsToTheEndOfAShorterMonth(): void
    {
        $this->assertStringEndsWith(
            'au 28/02/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 01/03/2027, soixante-quinze pour cent du prix de la location.',
            self::scale('2027-03-31', 90, '2026-10-01')->scaleSentence()
        );
        $this->assertStringEndsWith(
            'au 29/02/2028 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 01/03/2028, soixante-quinze pour cent du prix de la location.',
            self::scale('2028-03-30', 90, '2027-10-01')->scaleSentence()
        );
        $this->assertStringEndsWith(
            'au 30/06/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 01/07/2027, soixante-quinze pour cent du prix de la location.',
            self::scale('2027-07-31', 90, '2027-01-01')->scaleSentence()
        );
    }

    /** Booked after the deadline: the contract says the free period does not apply, and prints no past date. */
    public function testAPastDeadlineIsNeverOffered(): void
    {
        $scale = self::scale('2027-03-01', 90, '2027-01-01');

        $this->assertNull($scale->freeUntil);
        $this->assertStringContainsString('ne s\'applique pas à cette réservation', $scale->freeSentence());
        $this->assertStringNotContainsString('2026', $scale->freeSentence() . $scale->scaleSentence());
        $this->assertSame(
            'jusqu\'au 01/02/2027 inclus, cinquante pour cent du prix de la location ; '
                . 'à partir du 02/02/2027, soixante-quinze pour cent du prix de la location.',
            $scale->scaleSentence()
        );
    }

    public function testAPastDeadlineInsideTheLastMonthLeavesOnlyTheLastStretch(): void
    {
        $scale = self::scale('2027-01-20', 90, '2027-01-01');

        $this->assertNull($scale->freeUntil);
        $this->assertSame('soixante-quinze pour cent du prix de la location.', $scale->scaleSentence());
    }

    /** The last free day is today: still a free day. */
    public function testADeadlineFallingTodayIsStillOffered(): void
    {
        $this->assertSame('2027-04-02', self::scale('2027-07-01', 90, '2027-04-02')->freeUntil);
        $this->assertNull(self::scale('2027-07-01', 90, '2027-04-03')->freeUntil);
    }

    public function testNoDeadlineBeforeTheArrivalOwesNothing(): void
    {
        $scale = self::scale('2027-07-01', 0, '2027-01-01');

        $this->assertSame('2027-07-01', $scale->freeUntil);
        $this->assertStringStartsWith('aucune', $scale->scaleSentence());
    }

    public function testAnUnreadableArrivalHasNoScale(): void
    {
        $this->assertNull(WithdrawalScale::of('pas une date', 90, new \DateTimeImmutable()));
    }
}
