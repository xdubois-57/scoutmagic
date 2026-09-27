<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Parser;

use Modules\Finance\Api\FinanceException;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Parser\BnpParser;
use Modules\Finance\Parser\CodaParser;
use Modules\Finance\Parser\StatementLine;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ReferenceDataset\CodaRecords;

/**
 * The CODA parser, against files built record by record (CodaRecords) —
 * one test per trap the format sets (see the parser's own doc comment).
 */
class CodaParserTest extends TestCase
{
    private const UNIT = 'BE27000000000001';
    private const CAMPS = 'BE97000000000002';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    /**
     * Two accounts, one statement each: a debit and a credit on the first,
     * one credit on the second.
     *
     * @return list<string>
     */
    private function twoAccounts(): array
    {
        return [
            CodaRecords::header(),
            CodaRecords::oldBalance(self::UNIT, '012', 1_000_000, '310727'),
            CodaRecords::movement('0001', '0000', 'REF-UNITE-1', -35_980, '020827', '020827', 'Achat matériel'),
            CodaRecords::movement3('0001', '0000', 'BE71096123456769', 'Fournitures Bivouac'),
            CodaRecords::movement('0002', '0000', 'REF-UNITE-2', 93_000, '030827', '030827', 'Don de la famille'),
            CodaRecords::newBalance(self::UNIT, '012', 1_057_020, '030827'),
            CodaRecords::oldBalance(self::CAMPS, '004', 500_000, '310727'),
            CodaRecords::movement('0001', '0000', 'REF-CAMPS-1', 250_000, '050827', '050827', 'Participation camp'),
            CodaRecords::newBalance(self::CAMPS, '004', 750_000, '050827'),
            CodaRecords::trailer(),
        ];
    }

    /**
     * @param list<string> $records
     */
    private function write(array $records, string $encoding = 'ISO-8859-1'): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'coda_test_');
        file_put_contents($path, mb_convert_encoding(implode("\r\n", $records) . "\r\n", $encoding, 'UTF-8'));
        $this->paths[] = $path;

        return $path;
    }

    /**
     * @return list<StatementLine>
     */
    private function parse(array $records, string $encoding = 'ISO-8859-1'): array
    {
        return (new CodaParser())->parse($this->write($records, $encoding));
    }

    // -------------------------------------------------------- detection

    public function testTheFactoryRecognizesACodaFileAndABnpFileEachForWhatTheyAre(): void
    {
        $factory = new BankStatementParserFactory();

        $this->assertSame('coda', $factory->detect($this->write($this->twoAccounts())));
        $this->assertSame('bnp', $factory->detect(dirname(__DIR__, 3) . '/fixtures/finance/bnp_statement_sample.csv'));
    }

    public function testNeitherFormatClaimsAFileThatIsNeither(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'coda_test_');
        file_put_contents($path, "Date;Montant;Libellé\n01/09/2026;10,00;Test\n");
        $this->paths[] = $path;

        $this->assertNull((new BankStatementParserFactory())->detect($path));
        $this->assertFalse((new BnpParser())->recognizes($this->write($this->twoAccounts())));
    }

    // ----------------------------------------------- several accounts

    public function testAFileAnnouncesEveryAccountItCoversAndEveryLineKnowsItsOwn(): void
    {
        $path = $this->write($this->twoAccounts());

        $this->assertSame([self::UNIT, self::CAMPS], (new CodaParser())->extractAccountIbans($path));
        $this->assertSame(
            [self::UNIT, self::UNIT, self::CAMPS],
            array_map(static fn (StatementLine $l): string => $l->accountIban, (new CodaParser())->parse($path))
        );
    }

    /**
     * A Belgian account number (structure 0) is the IBAN it stands for.
     */
    public function testABelgianAccountNumberIsReadAsItsIban(): void
    {
        $records = $this->twoAccounts();
        $records[1] = CodaRecords::oldBalance('000000000001', '012', 1_000_000, '310727', '0');

        $this->assertSame([self::UNIT, self::CAMPS], (new CodaParser())->extractAccountIbans($this->write($records)));
    }

    // ---------------------------------------------------------- amounts

    /**
     * Thousandths, with the sign apart: 35 980 thousandths debited is
     * −35,98 €, never −35 980.
     */
    public function testAmountsAreThousandthsWithTheirSignApart(): void
    {
        $lines = $this->parse($this->twoAccounts());

        $this->assertSame([-35.98, 93.0, 250.0], array_map(static fn (StatementLine $l): float => $l->amount, $lines));
    }

    public function testTheClosingBalanceOfEveryAccountIsReadFromTheFile(): void
    {
        $balances = (new CodaParser())->closingBalances($this->write($this->twoAccounts()));

        $this->assertSame(1057.02, $balances[self::UNIT]->amount);
        $this->assertSame('2027-08-03', $balances[self::UNIT]->date->format('Y-m-d'));
        $this->assertSame(750.0, $balances[self::CAMPS]->amount);
    }

    /**
     * Two statements of one account in one file: the later one is the
     * balance the account stands at.
     */
    public function testTheLatestStatementOfAnAccountGivesItsBalance(): void
    {
        $records = [
            CodaRecords::header(),
            CodaRecords::oldBalance(self::UNIT, '012', 1_000_000, '310727'),
            CodaRecords::movement('0001', '0000', 'REF-1', -10_000, '020827', '020827', 'Un'),
            CodaRecords::newBalance(self::UNIT, '012', 990_000, '020827'),
            CodaRecords::oldBalance(self::UNIT, '013', 990_000, '020827'),
            CodaRecords::movement('0001', '0000', 'REF-2', -20_000, '090827', '090827', 'Deux'),
            CodaRecords::newBalance(self::UNIT, '013', 970_000, '090827'),
            CodaRecords::trailer(),
        ];

        $path = $this->write($records);

        $this->assertSame([self::UNIT], (new CodaParser())->extractAccountIbans($path));
        $this->assertSame(970.0, (new CodaParser())->closingBalances($path)[self::UNIT]->amount);
        $this->assertCount(2, (new CodaParser())->parse($path));
    }

    /**
     * Old balance plus movements must make the new balance. A file that
     * does not add up is refused whole — and the message carries no
     * amount, since no bank data leaves the import.
     */
    public function testAStatementThatDoesNotAddUpIsRefusedWithoutNamingAnAmount(): void
    {
        $records = $this->twoAccounts();
        $records[5] = CodaRecords::newBalance(self::UNIT, '012', 1_057_030, '030827');

        try {
            $this->parse($records);
            $this->fail('A statement whose movements do not reach its new balance must be refused.');
        } catch (FinanceException $e) {
            $this->assertStringContainsString('relevé n° 012', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/\d+[,.]\d{2}/', $e->getMessage());
        }
    }

    // --------------------------------------------------------- encoding

    /**
     * @return array<string, array{string}>
     */
    public static function encodings(): array
    {
        return ['ISO-8859-1' => ['ISO-8859-1'], 'CP850' => ['CP850'], 'UTF-8' => ['UTF-8']];
    }

    /**
     * The file is converted before any field is read: « matériel » and
     * « Frédéric Hénin » come out whole in every encoding a bank uses, and
     * no field shifts by the bytes an accent takes.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('encodings')]
    public function testAccentsSurviveEveryEncodingAndShiftNoField(string $encoding): void
    {
        $records = $this->twoAccounts();
        $records[3] = CodaRecords::movement3('0001', '0000', 'BE71096123456769', 'Frédéric Hénin');

        $lines = $this->parse($records, $encoding);

        $this->assertSame('Achat matériel', $lines[0]->label);
        $this->assertSame('Frédéric Hénin', $lines[0]->counterpartyName);
        $this->assertSame('BE71096123456769', $lines[0]->counterpartyAccount);
        $this->assertSame(-35.98, $lines[0]->amount);
    }

    // ---------------------------------------------------- continuations

    /**
     * A communication spread over 2.1, 2.2 and 2.3 is sewn back together
     * in order, exactly — the chunks are fixed-width slices of one text.
     */
    public function testACommunicationSpreadOverThreeRecordsIsSewnBackTogether(): void
    {
        $part1 = str_pad('Cotisation annuelle de la famille Dupont pour les', 53);
        $part2 = str_pad('trois enfants inscrits cette année, merci', 53);
        $records = [
            CodaRecords::header(),
            CodaRecords::oldBalance(self::UNIT, '012', 0, '310727'),
            CodaRecords::movement('0001', '0000', 'REF-1', 150_000, '020827', '020827', $part1),
            CodaRecords::movement2('0001', '0000', $part2),
            CodaRecords::movement3('0001', '0000', 'BE71096123456769', 'Famille Dupont', 'et bonne année'),
            CodaRecords::newBalance(self::UNIT, '012', 150_000, '020827'),
            CodaRecords::trailer(),
        ];

        $line = $this->parse($records)[0];

        $this->assertSame(
            'Cotisation annuelle de la famille Dupont pour les trois enfants inscrits cette année, merci et bonne année',
            $line->label
        );
        $this->assertSame('Famille Dupont', $line->counterpartyName);
    }

    public function testInformationRecordsLandInTheExtraDetailsNotTheLabel(): void
    {
        $records = $this->twoAccounts();
        array_splice($records, 4, 0, [CodaRecords::information('0001', '0000', 'REF-UNITE-1', 'Facture 2027-118')]);

        $line = $this->parse($records)[0];

        $this->assertSame('Achat matériel', $line->label);
        $this->assertStringContainsString('Information : Facture 2027-118', (string) $line->extraDetails);
    }

    // ------------------------------------------ structured communication

    /**
     * The structured communication has a field of its own, clean — not
     * concatenated into extraDetails — and the label shows it the way a
     * treasurer reads it.
     */
    public function testAStructuredCommunicationGetsItsOwnField(): void
    {
        $records = $this->twoAccounts();
        $records[7] = CodaRecords::movement('0001', '0000', 'REF-CAMPS-1', 250_000, '050827', '050827', '101126001000146', true);

        $line = $this->parse($records)[2];

        $this->assertSame('126001000146', $line->structuredCommunication);
        $this->assertSame('+++126/0010/00146+++', $line->label);
        $this->assertStringNotContainsString('126001000146', (string) $line->extraDetails);
    }

    public function testAFreeCommunicationHasNoStructuredOne(): void
    {
        $this->assertNull($this->parse($this->twoAccounts())[0]->structuredCommunication);
    }

    // ------------------------------------------------------ globalisation

    /**
     * A batch the bank books as one movement: the global line (detail
     * 0000) is the account's movement, its details are not — importing
     * them too would count the batch twice, and the statement would not
     * add up.
     */
    public function testOnlyTheGlobalLineOfAGlobalisedMovementIsImported(): void
    {
        $records = [
            CodaRecords::header(),
            CodaRecords::oldBalance(self::UNIT, '012', 0, '310727'),
            CodaRecords::movement('0001', '0000', 'REF-LOT', -300_000, '020827', '020827', 'Lot de virements', false, '1'),
            CodaRecords::movement('0001', '0001', 'REF-LOT', -100_000, '020827', '020827', 'Détail un'),
            CodaRecords::movement('0001', '0002', 'REF-LOT', -200_000, '020827', '020827', 'Détail deux'),
            CodaRecords::newBalance(self::UNIT, '012', -300_000, '020827'),
            CodaRecords::trailer(),
        ];

        $lines = $this->parse($records);

        $this->assertCount(1, $lines);
        $this->assertSame(-300.0, $lines[0]->amount);
    }

    // -------------------------------------------------- deduplication key

    /**
     * The same movement re-exported the following year — another
     * statement number, another sequence number, another file — keeps
     * its key; two different movements never share one.
     */
    public function testTheDedupKeyIsStableAcrossExportsAndYears(): void
    {
        $first = $this->parse($this->twoAccounts());

        $reExported = [
            CodaRecords::header('010128'),
            CodaRecords::oldBalance(self::UNIT, '001', 1_000_000, '310727'),
            CodaRecords::movement('0007', '0000', 'REF-UNITE-1', -35_980, '020827', '020827', 'Achat matériel', false, '0', '001'),
            CodaRecords::newBalance(self::UNIT, '001', 964_020, '020827'),
            CodaRecords::trailer(),
        ];
        $second = $this->parse($reExported);

        $this->assertSame($first[0]->bankReference, $second[0]->bankReference);
        $keys = array_map(static fn (StatementLine $l): string => $l->bankReference, $first);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertLessThanOrEqual(100, strlen($first[0]->bankReference), 'finance_transactions.bank_reference is a VARCHAR(100)');
    }

    // ------------------------------------------------------ malformed files

    public function testAStatementWithoutItsNewBalanceIsRefused(): void
    {
        $records = $this->twoAccounts();
        unset($records[8]);

        $this->expectException(FinanceException::class);
        $this->parse(array_values($records));
    }

    public function testAnUnknownRecordIsRefused(): void
    {
        $records = $this->twoAccounts();
        $records[2] = '7' . substr($records[2], 1);

        $this->expectException(FinanceException::class);
        $this->parse($records);
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        $this->expectException(FinanceException::class);
        (new CodaParser())->parse('/nonexistent/releve.cod');
    }
}
