<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Modules\Finance\Repository\MemberLookupRepository;
use Modules\Finance\Service\CampaignImportException;
use Modules\Finance\Service\CampaignImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampaignImportService $service;
    /** @var array<string, int> desk id => members.id */
    private array $memberIds = [];
    /** @var string[] absolute paths to clean up */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new CampaignImportService(new MemberLookupRepository($this->pdo));

        foreach (['D-100', 'D-200', 'D-300'] as $deskId) {
            $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
            $stmt->execute([$deskId]);
            $this->memberIds[$deskId] = (int) $this->pdo->lastInsertId();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ── the happy path ──────────────────────────────────────────────────

    public function testItReadsAnExportWithAnAmountColumnAdded(): void
    {
        $result = $this->service->read($this->spreadsheet(
            ['ID interne', 'Nom', 'Prénom', 'Section', 'Montant'],
            [
                [$this->memberIds['D-100'], 'Vandenbrande', 'Lucie', 'Baladins 1', '45,00'],
                [$this->memberIds['D-200'], 'Vandenbrande', 'Antoine', 'Louveteaux 2', '45,00'],
                [$this->memberIds['D-300'], 'Roskam', 'Timéo', 'Baladins 2', '38,25'],
            ]
        ));

        $this->assertSame(3, $result->count());
        $this->assertSame(12825, $result->totalCents());
        $this->assertSame($this->memberIds['D-100'], $result->rows[0]['member_id']);
        $this->assertSame(4500, $result->rows[0]['amount_cents']);
    }

    /**
     * Everything that is not the identifier or the amount is kept, and
     * becomes a merge variable of the reminder.
     */
    public function testTheOtherColumnsAreKeptAsMergeData(): void
    {
        $result = $this->service->read($this->spreadsheet(
            ['ID interne', 'Nom', 'Section', 'Montant'],
            [[$this->memberIds['D-100'], 'Vandenbrande', 'Baladins 1', '45,00']]
        ));

        $this->assertSame(['Nom', 'Section'], array_values(array_diff($result->mergeColumns, ['ID interne'])));
        $this->assertSame('Vandenbrande', $result->rows[0]['merge_data']['Nom']);
        $this->assertArrayNotHasKey('Montant', $result->rows[0]['merge_data']);
    }

    public function testAnEmptyLineIsSkippedRatherThanRefused(): void
    {
        $result = $this->service->read($this->spreadsheet(
            ['ID interne', 'Montant'],
            [
                [$this->memberIds['D-100'], '45,00'],
                ['', ''],
                [$this->memberIds['D-200'], '45,00'],
            ]
        ));

        $this->assertSame(2, $result->count());
    }

    /**
     * A treasurer who trimmed the export down to the columns they thought
     * they needed still has an exact key to work from. This is a lookup,
     * not a guess.
     */
    public function testTheDeskIdentifierIsAcceptedWhenTheInternalOneIsGone(): void
    {
        $result = $this->service->read($this->spreadsheet(
            ['Identifiant Desk', 'Montant'],
            [['D-200', '45,00']]
        ));

        $this->assertSame($this->memberIds['D-200'], $result->rows[0]['member_id']);
    }

    /**
     * A member re-created in Desk and later merged back resolves to the
     * identity they were merged into — otherwise a treasurer working from
     * an older export would be told the line designates nobody.
     */
    public function testAMergedIdentityResolvesToTheOneItWasMergedInto(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id, merged_into_member_id) VALUES ('D-999', {$this->memberIds['D-100']})");
        $abandonedId = (int) $this->pdo->lastInsertId();

        $result = $this->service->read($this->spreadsheet(
            ['ID interne', 'Montant'],
            [[$abandonedId, '45,00']]
        ));

        $this->assertSame($this->memberIds['D-100'], $result->rows[0]['member_id']);
    }

    // ── the refusals ────────────────────────────────────────────────────

    /**
     * The design decision this whole class exists to enforce: no
     * approximate matching on a name. A file with no identifier column is
     * refused, and the refusal says on the spot what to do — sending
     * somebody to a help page without explaining anything is a refusal
     * done badly.
     */
    public function testAFileWithoutAnIdentifierColumnIsRefusedWithAnExplanation(): void
    {
        try {
            $this->service->read($this->spreadsheet(
                ['Nom', 'Prénom', 'Montant'],
                [['Vandenbrande', 'Lucie', '45,00']]
            ));
            $this->fail('A file with no identifier column must be refused.');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('ID interne', $e->getMessage());
            $this->assertStringContainsString('export des membres', $e->getMessage());
        }
    }

    public function testAFileWithoutAnAmountColumnIsRefused(): void
    {
        $this->expectException(CampaignImportException::class);
        $this->expectExceptionMessageMatches('/Montant/');

        $this->service->read($this->spreadsheet(['ID interne', 'Nom'], [[1, 'Vandenbrande']]));
    }

    public function testOneUnknownIdentifierRefusesTheWholeFileAndNamesEveryBadLine(): void
    {
        try {
            $this->service->read($this->spreadsheet(
                ['ID interne', 'Nom', 'Montant'],
                [
                    [$this->memberIds['D-100'], 'Vandenbrande', '45,00'],
                    ['4821', 'Inconnu', '45,00'],
                    ['', 'Sans identifiant', '45,00'],
                ]
            ));
            $this->fail('An unknown identifier must refuse the whole file.');
        } catch (CampaignImportException $e) {
            $this->assertCount(2, $e->lines);
            $this->assertSame(3, $e->lines[0]['line']);
            $this->assertStringContainsString('inconnu', $e->lines[0]['problem']);
            $this->assertSame(4, $e->lines[1]['line']);
            $this->assertStringContainsString('vide', $e->lines[1]['problem']);
            // What the line actually says, so the treasurer recognises it
            // without going back to the file.
            $this->assertStringContainsString('Inconnu', $e->lines[0]['content']);
        }
    }

    public function testAnUnreadableAmountRefusesTheFile(): void
    {
        try {
            $this->service->read($this->spreadsheet(
                ['ID interne', 'Montant'],
                [[$this->memberIds['D-100'], 'à définir']]
            ));
            $this->fail('An unreadable amount must refuse the file.');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('Montant illisible', $e->lines[0]['problem']);
        }
    }

    public function testAZeroAmountRefusesTheFile(): void
    {
        try {
            $this->service->read($this->spreadsheet(
                ['ID interne', 'Montant'],
                [[$this->memberIds['D-100'], '0']]
            ));
            $this->fail('A zero amount must refuse the file.');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('supérieur à zéro', $e->lines[0]['problem']);
        }
    }

    /**
     * One receivable per member: the same member twice in one campaign
     * would mint two communications for one person and make the second
     * payment ambiguous.
     */
    public function testTheSameMemberTwiceRefusesTheFile(): void
    {
        try {
            $this->service->read($this->spreadsheet(
                ['ID interne', 'Montant'],
                [
                    [$this->memberIds['D-100'], '45,00'],
                    [$this->memberIds['D-100'], '38,25'],
                ]
            ));
            $this->fail('A duplicate member must refuse the file.');
        } catch (CampaignImportException $e) {
            $this->assertStringContainsString('apparaît déjà à la ligne 2', $e->lines[0]['problem']);
        }
    }

    public function testTwoColumnsWithTheSameHeaderRefuseTheFile(): void
    {
        $this->expectException(CampaignImportException::class);
        $this->expectExceptionMessageMatches('/même en-tête/');

        $this->service->read($this->spreadsheet(['ID interne', 'Montant', 'montant'], [[1, '45,00', '45,00']]));
    }

    public function testAFileWithNoDataRowIsRefused(): void
    {
        $this->expectException(CampaignImportException::class);
        $this->expectExceptionMessageMatches('/aucune ligne/');

        $this->service->read($this->spreadsheet(['ID interne', 'Montant'], []));
    }

    public function testSomethingThatIsNotASpreadsheetIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'campaign_') ?: '';
        $this->tempFiles[] = $path;
        file_put_contents($path, 'nom;montant');

        $this->expectException(CampaignImportException::class);
        $this->expectExceptionMessageMatches('/Excel/');

        $this->service->read($path);
    }

    // ── amounts, as Belgian spreadsheets actually write them ────────────

    /**
     * @param string $raw
     * @param ?int $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountProvider')]
    public function testAmountsAreReadTheWayASpreadsheetWritesThem(string $raw, ?int $expected): void
    {
        $this->assertSame($expected, CampaignImportService::parseAmountCents($raw));
    }

    /**
     * @return array<string, array{string, ?int}>
     */
    public static function amountProvider(): array
    {
        return [
            'comma decimal' => ['38,25', 3825],
            'dot decimal' => ['38.25', 3825],
            'with a currency sign' => ['€ 45,00', 4500],
            'a whole number' => ['45', 4500],
            'one decimal' => ['45,5', 4550],
            'a thousands separator' => ['1 234,50', 123450],
            // Three digits after the last separator is a thousands
            // separator, not a decimal one: "1.500" is fifteen hundred
            // euros, not one euro fifty.
            'a dotted thousand' => ['1.500', 150000],
            'empty' => ['', null],
            'words' => ['à définir', null],
        ];
    }

    // ── helper ──────────────────────────────────────────────────────────

    /**
     * @param string[] $headers
     * @param array<int, array<int, string|int>> $rows
     */
    private function spreadsheet(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }
        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $cellIndex => $value) {
                $sheet->setCellValue([$cellIndex + 1, $rowIndex + 2], $value);
            }
        }

        $path = (tempnam(sys_get_temp_dir(), 'campaign_') ?: '') . '.xlsx';
        $this->tempFiles[] = $path;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
