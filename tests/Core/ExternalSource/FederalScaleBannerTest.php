<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\FederalScaleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalScaleBannerTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function orders(): iterable
    {
        yield 'latest banner first' => [true];
        yield 'oldest banner first' => [false];
    }

    private static function page(bool $latestFirst, bool $currentIama = true, bool $sharedPolicy = true): string
    {
        $current = '<div class="cmpt-banner-noLink"><h3>Cotisations 2026-2027</h3>'
            . '<p>Cotisation normale : 40 €</p><p>Cotisation couple : 32 €</p>'
            . '<p>Cotisation familiale : 27 €</p><p>Cotisation invités : 18,25 €</p>'
            . ($currentIama ? '<p>Les IAmA liés à ton unité devront payer une cotisation de 25€.</p>' : '')
            . '</div>';
        $old = '<div class="cmpt-banner-noLink"><h3>Cotisations 2025-2026</h3>'
            . '<p>Cotisation normale : 37 €</p><p>Cotisation couple : 30 €</p>'
            . '<p>Cotisation familiale : 25 €</p><p>Cotisation invités : 17,50 €</p>'
            . '<p>Les IAmA liés à ton unité devront payer une cotisation de 24€.</p>'
            . '<p>Entre le 1er mars et le 31 mai, une inscription coute une cotisation invité.</p></div>';
        $policy = '<div class="cmpt-framed"><h3>Invité·e, intendant·e...</h3>'
            . '<p>Entre le 1er juin et le 31 aout, une inscription coute une cotisation invité.</p></div>';

        return ($latestFirst ? $current . $old : $old . $current) . ($sharedPolicy ? $policy : '');
    }

    #[DataProvider('orders')]
    public function testTheLatestBannerKeepsItsOwnAmountsAndTheSharedAnnualPolicy(bool $latestFirst): void
    {
        $scale = FederalScaleExtractor::extract(self::page($latestFirst));

        self::assertSame([4000, 3200, 2700, 2500, 1825, '01/06', '31/08', '2026-2027'], [
            $scale?->normalCents, $scale?->coupleCents, $scale?->familyCents, $scale?->iamaCents,
            $scale?->guestCents, $scale?->guestFromDayMonth, $scale?->guestUntilDayMonth, $scale?->year,
        ]);
    }

    #[DataProvider('orders')]
    public function testTheRequestedOlderBannerKeepsItsOwnExplicitPeriodAndAmounts(bool $latestFirst): void
    {
        $section = FederalScaleExtractor::sectionForYear(self::page($latestFirst), '2025-2026');
        $scale = FederalScaleExtractor::extract($section ?? '');

        self::assertSame([3700, 3000, 2500, 2400, 1750, '01/03', '31/05', '2025-2026'], [
            $scale?->normalCents, $scale?->coupleCents, $scale?->familyCents, $scale?->iamaCents,
            $scale?->guestCents, $scale?->guestFromDayMonth, $scale?->guestUntilDayMonth, $scale?->year,
        ]);
    }

    #[DataProvider('orders')]
    public function testAMissingIamaAmountIsNotBorrowedFromTheOlderBanner(bool $latestFirst): void
    {
        self::assertNull(FederalScaleExtractor::extract(self::page($latestFirst, currentIama: false)));
    }

    #[DataProvider('orders')]
    public function testAnOlderSeasonPeriodIsNotAssumedToBeASharedPolicy(bool $latestFirst): void
    {
        self::assertNull(FederalScaleExtractor::extract(self::page($latestFirst, sharedPolicy: false)));
    }
}
