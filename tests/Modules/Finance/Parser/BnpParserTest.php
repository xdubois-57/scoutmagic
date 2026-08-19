<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Parser;

use Modules\Finance\Parser\BnpParser;
use Modules\Finance\Service\FinanceException;
use PHPUnit\Framework\TestCase;

class BnpParserTest extends TestCase
{
    private string $fixturePath;
    private BnpParser $parser;

    protected function setUp(): void
    {
        $this->fixturePath = dirname(__DIR__, 3) . '/fixtures/finance/bnp_statement_sample.csv';
        $this->parser = new BnpParser();
    }

    public function testExtractSourceIban(): void
    {
        $this->assertSame('BE00000000000001', $this->parser->extractSourceIban($this->fixturePath));
    }

    public function testExtractSourceIbanThrowsWhenFileMissing(): void
    {
        $this->expectException(FinanceException::class);
        $this->parser->extractSourceIban('/nonexistent/path.csv');
    }

    public function testParseSkipsRefusedLines(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        // Fixture has 4 data rows, one with status "Refusé" — expect 3.
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertNotSame('2609030000000003', $line->bankReference);
        }
    }

    public function testParseExtractsBankReferenceFromDetails(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame('2609010000000001', $lines[0]->bankReference);
        $this->assertSame('2609020000000002', $lines[1]->bankReference);
    }

    public function testParseHandlesCommaDecimalAmounts(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame(-35.98, $lines[0]->amount);
        $this->assertSame(93.0, $lines[1]->amount);
    }

    public function testParseHandlesThousandsSeparator(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        // Fourth data row (index 2 after skipping the refused one): "1.234,56"
        $this->assertSame(1234.56, $lines[2]->amount);
    }

    public function testParseUsesCommunicationAsLabelWhenPresent(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame('Cotisation', $lines[0]->label);
    }

    public function testParseFallsBackToDetailsWhenCommunicationEmpty(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        // Fourth data row has an empty "Communication" column.
        $this->assertStringContainsString('VIREMENT SANS COMMUNICATION', $lines[2]->label);
    }

    public function testParseExtractsCounterpartyAccount(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame('BE00000000000002', $lines[0]->counterpartyAccount);
    }

    public function testParseExtractsCounterpartyName(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame('Jean Dupont', $lines[0]->counterpartyName);
        $this->assertSame('Marie Martin', $lines[1]->counterpartyName);
    }

    public function testParseConcatenatesUnmappedColumnsIntoExtraDetails(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        // "Date valeur" equals "Date d'exécution" in the fixture, so it's
        // omitted as noise — only "Type de transaction" shows up.
        $this->assertSame('Type : Virement en euros', $lines[0]->extraDetails);
        $this->assertSame('Type : Virement instantané en euros', $lines[1]->extraDetails);
    }

    public function testParseExtractsExecutionDate(): void
    {
        $lines = $this->parser->parse($this->fixturePath);

        $this->assertSame('2026-09-01', $lines[0]->transactionDate->format('Y-m-d'));
    }

    public function testParseThrowsOnMissingFile(): void
    {
        $this->expectException(FinanceException::class);
        $this->parser->parse('/nonexistent/path.csv');
    }

    public function testParseIgnoresTrailingBlankLine(): void
    {
        // The fixture ends with a blank CRLF line — must not become a
        // bogus StatementLine.
        $lines = $this->parser->parse($this->fixturePath);
        $this->assertCount(3, $lines);
    }
    /**
     * Regression: extractSourceIban() only trimmed, while the account's own
     * IBAN is stored normalized — Service\ImportService::verifyIban()
     * compares blind indexes, so a formatted value here aborted every
     * import of the right file with a misleading "IBAN mismatch".
     */
    public function testExtractSourceIbanNormalizesAFormattedValue(): void
    {
        $path = $this->writeCsv([
            ['2026-', '01/09/2026', '01/09/2026', '-35,98', 'EUR', 'BE00 0000 0000 0001', 'Virement', '', '', 'Test', 'REFERENCE BANQUE : 1', 'Accepté', ''],
        ]);

        $this->assertSame('BE00000000000001', (new BnpParser())->extractSourceIban($path));

        unlink($path);
    }

    /**
     * Regression: "." was stripped unconditionally as a thousands
     * separator, so a dot-decimal amount was silently multiplied by 100 —
     * no error, just a wrong movement and a wrong balance checkpoint.
     *
     * @dataProvider amountFormats
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountFormats')]
    public function testParseAmountReadsBothDecimalConventions(string $raw, float $expected): void
    {
        $path = $this->writeCsv([
            ['2026-', '01/09/2026', '01/09/2026', $raw, 'EUR', 'BE00000000000001', 'Virement', '', '', 'Test', 'REFERENCE BANQUE : 1', 'Accepté', ''],
        ]);

        $lines = (new BnpParser())->parse($path);

        $this->assertSame($expected, $lines[0]->amount, $raw);

        unlink($path);
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function amountFormats(): array
    {
        return [
            'belgian decimal comma' => ['35,98', 35.98],
            'belgian negative' => ['-35,98', -35.98],
            'belgian thousands and decimals' => ['1.234,56', 1234.56],
            'belgian millions' => ['1.234.567,89', 1234567.89],
            'dot decimal' => ['35.98', 35.98],
            'dot decimal negative' => ['-35.98', -35.98],
            'plain integer' => ['100', 100.0],
            'multiple dots without comma' => ['1.234.567', 1234567.0],
        ];
    }

    public function testParseRejectsAnUnparseableAmount(): void
    {
        $path = $this->writeCsv([
            ['2026-', '01/09/2026', '01/09/2026', 'beaucoup', 'EUR', 'BE00000000000001', 'Virement', '', '', 'Test', 'REFERENCE BANQUE : 1', 'Accepté', ''],
        ]);

        $this->expectException(FinanceException::class);
        try {
            (new BnpParser())->parse($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bnp_test_') . '.csv';
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv(
            $handle,
            ['Nº de séquence', "Date d'exécution", 'Date valeur', 'Montant', 'Devise du compte', 'Numéro de compte',
             'Type de transaction', 'Contrepartie', 'Nom de la contrepartie', 'Communication', 'Détails', 'Statut', 'Motif du refus'],
            ';', '"', '\\'
        );
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '\\');
        }
        fclose($handle);

        return $path;
    }

}
