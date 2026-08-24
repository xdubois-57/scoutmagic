<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Modules\Camps\Repository\Camp;
use Modules\Camps\Service\CampLabels;
use PHPUnit\Framework\TestCase;

class CampLabelsTest extends TestCase
{
    /**
     * @dataProvider ranges
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ranges')]
    public function testADateRangeReadsLikeASentence(?string $start, ?string $end, ?int $year, string $expected): void
    {
        $this->assertSame($expected, CampLabels::dateRange($start, $end, $year));
    }

    /**
     * @return array<string, array{?string, ?string, ?int, string}>
     */
    public static function ranges(): array
    {
        return [
            'within one month' => ['2028-07-12', '2028-07-19', null, '12–19 juillet 2028'],
            'across two months' => ['2026-04-30', '2026-05-03', null, '30 avril – 3 mai 2026'],
            'across two years' => ['2026-12-30', '2027-01-02', null, '30 décembre 2026 – 2 janvier 2027'],
            'a single day' => ['2026-05-01', '2026-05-01', null, '1er mai 2026'],
            'starting on the first' => ['2026-05-01', '2026-05-03', null, '1er–3 mai 2026'],
            'a bare year' => [null, null, 2029, '2029'],
            'nothing at all' => [null, null, null, ''],
        ];
    }

    public function testMoneyIsBelgianFrenchAndAbsenceIsNotZero(): void
    {
        $this->assertSame('2 450,00 €', CampLabels::money(245000));
        $this->assertSame('0,50 €', CampLabels::money(50));
        $this->assertNull(CampLabels::money(null));
    }

    public function testACancelledStayIsGreyNotRed(): void
    {
        // One severity, one colour, across the whole site
        // (partials/status_badge.html.twig): a cancelled stay is a fact
        // with nothing left to do about it, not an error.
        $this->assertSame('cancelled', CampLabels::statusTone(Camp::STATUS_CANCELLED));
        $this->assertSame('pending', CampLabels::statusTone(Camp::STATUS_TO_CONFIRM));
        $this->assertSame('confirmed', CampLabels::statusTone(Camp::STATUS_CONFIRMED));
    }

    public function testAnUnknownValueFallsBackRatherThanRenderingBlank(): void
    {
        $this->assertSame('croisiere', CampLabels::stayType('croisiere'));
        $this->assertSame('neutral', CampLabels::statusTone('inconnu'));
    }

    public function testEveryStatusAndStayTypeHasALabelAndATone(): void
    {
        // A value the schema allows but the vocabulary forgot would render
        // as a raw enum string on the camp's headline.
        foreach ([Camp::STATUS_TO_CONFIRM, Camp::STATUS_CONFIRMED, Camp::STATUS_CANCELLED] as $status) {
            $this->assertArrayHasKey($status, CampLabels::STATUSES);
            $this->assertArrayHasKey($status, CampLabels::STATUS_TONES);
        }
        foreach ([Camp::STAY_GRAND_CAMP, Camp::STAY_WEEKEND, Camp::STAY_OTHER] as $type) {
            $this->assertArrayHasKey($type, CampLabels::STAY_TYPES);
        }
    }
}
