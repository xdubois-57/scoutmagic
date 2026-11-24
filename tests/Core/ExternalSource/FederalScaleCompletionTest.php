<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\FederalScaleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FederalScaleCompletionTest extends TestCase
{
    private static function page(
        string $guestWindow = 'Entre le 1er juin et le 31 aout, une inscription coute une cotisation invité.'
    ): string {
        return '<h3>Cotisations 2026-2027</h3>'
            . '<p>Cotisation normale : 40 €</p><p>Cotisation couple : 32 € par personne</p>'
            . '<p>Cotisation familiale : 27 € par personne</p>'
            . '<p>Cotisation invités et demi-année (à partir du 1er juin 2025) : 18,25 €</p>'
            . '<h4>Les IAmA</h4><p>Un IAmA uniquement fédéral ne paie pas de cotisation.</p>'
            . '<p>Les IAmA liés à ton unité devront payer une cotisation de 25€.</p>'
            . '<h3>Invités</h3><p>Du 15 aout au 31 mai, une inscription occasionnelle est gratuite.</p>'
            . '<p>' . $guestWindow . '</p>';
    }

    public function testAllFiveAmountsAndThePaidWindowComeFromTheSameSeason(): void
    {
        $scale = FederalScaleExtractor::extract(self::page());
        $values = $scale === null ? [] : get_object_vars($scale);

        self::assertSame([4000, 3200, 2700, 2500, 1825, '01/06', '31/08', '2026-2027'], [
            $values['normalCents'] ?? null, $values['coupleCents'] ?? null, $values['familyCents'] ?? null,
            $values['iamaCents'] ?? null, $values['guestCents'] ?? null,
            $values['guestFromDayMonth'] ?? null, $values['guestUntilDayMonth'] ?? null, $values['year'] ?? null,
        ]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingFacts(): iterable
    {
        yield 'local IAmA amount' => ['une cotisation de 25€', 'une cotisation non précisée'];
        yield 'guest amount' => ['18,25 €', 'montant absent'];
        yield 'paid window start' => ['Entre le 1er juin', 'Entre une date inconnue'];
        yield 'paid window end' => ['et le 31 aout', 'et une date inconnue'];
        yield 'impossible calendar date' => ['et le 31 aout', 'et le 31 septembre'];
        yield 'reversed window' => ['et le 31 aout', 'et le 31 mai'];
    }

    #[DataProvider('missingFacts')]
    public function testAnIncompleteOrImpossibleScaleCannotPassTheDeterministicWatch(string $from, string $to): void
    {
        self::assertNull(FederalScaleExtractor::extract(str_replace($from, $to, self::page())));
    }

    public function testASeasonMentionInProseDoesNotBecomeANewHeading(): void
    {
        $html = self::page() . '<p>Voir les cotisations 2028-2029 quand elles seront publiées.</p>';

        self::assertSame('2026-2027', FederalScaleExtractor::extract($html)?->year);
    }

    public function testAnOlderRequestedSeasonIsSelectedWithoutTakingTheLatestValues(): void
    {
        $old = str_replace(['2026-2027', '40 €'], ['2025-2026', '37 €'], self::page());
        $wanted = FederalScaleExtractor::text($old);

        self::assertSame([$wanted, $wanted], [
            FederalScaleExtractor::sectionForYear(self::page() . $old, '2025-2026'),
            FederalScaleExtractor::sectionForYear($old . self::page(), '2025-2026'),
        ]);
    }

    public function testAnAbsentOrInvalidSeasonIsNeverInventedFromProse(): void
    {
        $html = self::page() . '<p>Les cotisations 2025-2026 ne sont plus détaillées ici.</p>'
            . str_replace('2026-2027', '2026-2028', self::page());

        self::assertSame([null, null], [
            FederalScaleExtractor::sectionForYear($html, '2025-2026'),
            FederalScaleExtractor::sectionForYear($html, '2026-2028'),
        ]);
    }

    public function testTheGuestCalendarUsesTheSeasonEndingYear(): void
    {
        $html = str_replace(['2026-2027', 'Entre le 1er juin'], ['2027-2028', 'Entre le 29 février'], self::page());

        self::assertSame('29/02', FederalScaleExtractor::extract($html)?->guestFromDayMonth);
    }

    /** @return iterable<string, array{string, string}> */
    public static function adjacentWindows(): iterable
    {
        foreach (['15 aout et le 31 mai', '1er mars et le 31 mai'] as $freeWindow) {
            foreach (['. ', '; ', ' et '] as $separator) {
                yield $freeWindow . $separator => [$freeWindow, $separator];
            }
        }
    }

    #[DataProvider('adjacentWindows')]
    public function testAFreeWindowCannotBorrowThePaidPredicateFromTheNextWindow(
        string $freeWindow,
        string $separator
    ): void {
        $page = self::page('Entre le ' . $freeWindow . ', une inscription est gratuite' . $separator
            . 'Entre le 1er juin et le 31 aout, une inscription coute une cotisation invité.');
        $scale = FederalScaleExtractor::extract($page);

        self::assertSame(['01/06', '31/08'], [$scale?->guestFromDayMonth, $scale?->guestUntilDayMonth]);
    }
}
