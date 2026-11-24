<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Fees\Invoice;

use Core\File\PdfTextExtractor;
use Modules\Fees\Invoice\InvoiceLine;
use Modules\Fees\Invoice\InvoiceParser;
use Modules\Fees\Invoice\InvoicePerson;
use Modules\Fees\Invoice\InvoiceProblem;
use Modules\Fees\Invoice\InvoiceReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceSummaryReaderTest extends TestCase
{
    /** @param list<list<string>> $pages */
    private function read(array $pages): \Modules\Fees\Invoice\InvoiceReadResult
    {
        return (new InvoiceReader(new PdfTextExtractor(), new InvoiceParser()))->read(SyntheticInvoicePdf::build($pages));
    }

    /** @return iterable<string, array{string}> */
    public static function summaryLayouts(): iterable
    {
        yield 'spaced' => ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00'];
        yield 'glued' => ["ZX777B1COTISATION NORMALECOT_NORM\t2,00 80,0040,00"];
    }

    #[DataProvider('summaryLayouts')]
    public function testVatSummaryDoesNotBecomeAFeeAndUnnamedFeesKeepTheirNature(string $tariff): void
    {
        $result = $this->read([[
            $tariff,
            '0 % 80 0 80',
            '0,00 % 80,00 0,00 80,00',
            'TOTAL A PAYER 80,00',
        ]]);

        self::assertSame([true, [[InvoiceLine::NATURE_FEE, [], 8000]]], [
            $result->isAccepted(),
            array_map(static fn(InvoiceLine $line): array => [$line->nature(), $line->people, $line->amountCents],
                $result->invoice?->lines ?? []),
        ]);
    }

    /** @return iterable<string, array{bool}> */
    public static function independentLines(): iterable
    {
        yield 'same page' => [false];
        yield 'next page' => [true];
    }

    #[DataProvider('independentLines')]
    public function testIdenticalIamaFeesForDifferentPeopleStayIndependent(bool $pageBreak): void
    {
        $first = ['COT_iAM_LOCAL Cotisation IAmA 25,00 1 25,00', 'SOLIS Ada 11/02/2001 Membre'];
        $second = ['COT_iAM_LOCAL Cotisation IAmA 25,00 1 25,00', 'VEGA Iris 17/04/2002 Membre', 'TOTAL A PAYER 50,00'];
        $pages = $pageBreak ? [[...$first, 'Page 1 sur 2'], $second] : [[...$first, ...$second]];

        $result = $this->read($pages);

        self::assertSame([true, 2, 5000, 0], [$result->isAccepted(), count($result->invoice?->lines ?? []),
            $result->invoice?->recomputedTotalCents(), $result->stats->repeatedTariffRows]);
    }

    /** @return iterable<string, array{string, string, ?int}> */
    public static function pageMarkers(): iterable
    {
        yield 'footer' => ['Page 1 sur 2', '', 2];
        yield 'attached header' => ['', 'Page 2/2XX', 2];
        yield 'space after slash' => ['', 'Page 2/ 2XX', 2];
        yield 'spaces around slash' => ['', 'Page 2 / 2<>', 2];
        yield 'table header only' => ['', '', null];
    }

    #[DataProvider('pageMarkers')]
    public function testAPartialListContinuesAcrossAPageWithoutDoubleBilling(
        string $footer,
        string $header,
        ?int $pageCount
    ): void
    {
        $result = $this->read([
            ['COT_INVITE Cotisation invites ZX777B1 18,25 2 36,50', 'SOLIS Ada 11/02/2001 Membre', $footer],
            [$header, 'Reference Description Section Prix Quantite Montant',
                'COT_INVITE Cotisation invites ZX777B1 18,25 2 36,50',
                'VEGA Iris 17/04/2002 Membre', 'TOTAL A PAYER 36,50'],
        ]);

        self::assertSame([true, 1, 2, 1, $pageCount], [$result->isAccepted(), count($result->invoice?->lines ?? []),
            count($result->invoice?->lines[0]->people ?? []), $result->stats->repeatedTariffRows, $result->stats->pageCount]);
    }

    public function testTheFirstPageCanLackItsFooterWhenThePrintedCountConfirmsTheExtractorSeparators(): void
    {
        $result = $this->read([
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'SOLIS Ada 11/02/2001 Membre'],
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'VEGA Iris 17/04/2002 Membre',
                'TOTAL A PAYER 80,00', 'Page 2/2XX'],
        ]);

        self::assertSame([true, 1, 2, 1], [$result->isAccepted(), count($result->invoice?->lines ?? []),
            count($result->invoice?->lines[0]->people ?? []), $result->stats->repeatedTariffRows]);
    }

    public function testABlankSeparatorWithoutPageOrHeaderEvidenceDoesNotMergeCharges(): void
    {
        $result = $this->read([
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'SOLIS Ada 11/02/2001 Membre'],
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'VEGA Iris 17/04/2002 Membre',
                'TOTAL A PAYER 80,00'],
        ]);

        self::assertFalse($result->isAccepted());
    }

    public function testAFirstNamePageFollowedByABirthDateIsNotPagination(): void
    {
        $result = $this->read([[
            'COT_NORM Cotisation normale ZX777B1 40,00 1 40,00',
            'SOLIS Page 11/02/2001 Membre',
            'TOTAL A PAYER 40,00',
        ]]);

        self::assertSame([true, ['Page'], null], [$result->isAccepted(),
            array_map(static fn(InvoicePerson $person): string => $person->firstName,
                $result->invoice?->lines[0]->people ?? []),
            $result->stats->pageCount]);
    }

    /** @return iterable<string, array{bool}> */
    public static function listsOnOnePage(): iterable
    {
        yield 'list finishes before repeated header' => [true];
        yield 'list starts after repeated header' => [false];
    }

    #[DataProvider('listsOnOnePage')]
    public function testARepeatedHeaderCanHaveItsWholeListOnJustOneSideOfThePageBreak(bool $onFirstPage): void
    {
        $names = ['SOLIS Ada 11/02/2001 Membre', 'VEGA Iris 17/04/2002 Membre'];
        $tariff = 'COT_INVITE Cotisation invites ZX777B1 18,25 2 36,50';
        $result = $this->read([
            [$tariff, ...($onFirstPage ? $names : [])],
            [$tariff, ...($onFirstPage ? [] : $names), 'TOTAL A PAYER 36,50', 'Page 2/2'],
        ]);

        self::assertSame([true, 1, 2, 1], [$result->isAccepted(), count($result->invoice?->lines ?? []),
            count($result->invoice?->lines[0]->people ?? []), $result->stats->repeatedTariffRows]);
    }

    public function testUnprovenPageRepetitionIsRefusedRatherThanGuessed(): void
    {
        $result = $this->read([
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'Page 1 sur 2'],
            ['COT_NORM Cotisation normale ZX777B1 40,00 2 80,00', 'TOTAL A PAYER 80,00'],
        ]);

        self::assertSame([false, ['ambiguous_repetition']], [$result->isAccepted(), array_column($result->problems, 'kind')]);
    }

    public function testAnUnknownShapedReferenceAndAZeroQuantityAreNotDiscarded(): void
    {
        $result = $this->read([['NEW_TARIFF Tarif futur ZX777B1 8,00 0 1,00', 'TOTAL A PAYER 1,00']]);

        self::assertSame([false, [InvoiceProblem::LINE_ARITHMETIC]],
            [$result->isAccepted(), array_column($result->problems, 'kind')]);
    }

    public function testUnnamedReductionAndAdvanceDeductionHaveDifferentNatures(): void
    {
        $result = $this->read([[
            'COT_NORM Cotisation normale ZX777B1 40,00 2 80,00',
            'RED_ANIM_BREV Reduction brevet ZX777B1 -6,00 1 -6,00',
            'COT_ACOMPTE Deduction acompte 20,00 -1,00 -20,00',
            'TOTAL A PAYER 54,00',
        ]]);

        self::assertSame([true, [InvoiceLine::NATURE_FEE, InvoiceLine::NATURE_REDUCTION, InvoiceLine::NATURE_ADJUSTMENT]],
            [$result->isAccepted(),
                array_map(static fn(InvoiceLine $line): string => $line->nature(), $result->invoice?->lines ?? [])]);
    }
}
