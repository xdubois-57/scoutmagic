<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\ScoutYearService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRow;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\MemberLookupRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\CampaignImportService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Finance\Service\TreasurerScope;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * The unit's calendar sale: the campaign, its roster, and the half-settled
 * bank statement that follows it.
 *
 * **The spreadsheet is real.** `CampaignService::createFromFile()` reads an
 * `.xlsx` through PhpSpreadsheet, refuses the whole file if a single line
 * resolves to nobody, stores the file encrypted, and raises one receivable
 * per row inside a transaction. There is no back door past that, and there
 * should not be: the refusal is the module's most important behaviour, and a
 * fixture that skipped it would be a fixture that never proved the roster
 * resolves. So this class writes the file the treasurer would have uploaded
 * — from the dataset's own Tiers, which is what the site's member export puts
 * in that column — hands it over, and deletes it afterwards.
 *
 * **The payments come back through the bank import.** A campaign's
 * communications are random (StructuredCommunicationService::generate()), so
 * they cannot be pre-baked into the committed statements: they only exist
 * once the campaign does. The seeder therefore reads them back, writes a
 * seventh BNP statement in the very shape BnpCsvWriter produces for the six
 * committed ones, and imports it through the same
 * Modules\Finance\Service\ImportService — which categorises, deduplicates
 * and, at the end, reconciles the account. Nothing writes an allocation by
 * hand; every match on that page was computed by the application.
 */
final class CampaignSeeder
{
    private readonly CampaignService $campaignService;

    private readonly CampaignRowRepository $rowRepository;

    private readonly ExpectedReceivableRepository $receivableRepository;

    private readonly ScoutYearService $scoutYearService;

    /**
     * @param array<string, int> $memberIds Tiers => members.id, used only to
     *        keep the roster to members this instance actually has
     */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        private readonly string $storagePath,
        private readonly string $datasetRoot,
        private readonly array $memberIds,
        private readonly ?int $actorId,
    ) {
        $this->rowRepository = new CampaignRowRepository($pdo, $encryption);
        $this->receivableRepository = new ExpectedReceivableRepository($pdo, $encryption);
        $this->scoutYearService = new ScoutYearService($pdo);

        $accountRepository = new AccountRepository($pdo, $encryption);
        $allocationService = new ReceivableAllocationService(
            $this->receivableRepository,
            new ReceivableAllocationRepository($pdo),
            new TransactionRepository($pdo, $encryption),
            $accountRepository,
            // A builder acts for the installation, not for a person.
            new AccountVisibility(TreasurerScope::systemCaller()),
        );

        $this->campaignService = new CampaignService(
            $pdo,
            new CampaignRepository($pdo),
            $this->rowRepository,
            new CampaignImportService(new MemberLookupRepository($pdo)),
            new ExpectedReceivableService($this->receivableRepository, $allocationService),
            new StructuredCommunicationService($this->receivableRepository),
            $accountRepository,
            new AccountVisibility(TreasurerScope::systemCaller()),
            new EncryptedFileStorageService(new FileRepository($pdo), $encryption, $this->storagePath),
            new JournalService(new JournalRepository($pdo)),
        );
    }

    /**
     * @param callable(string, string): array{imported: int, duplicates: int} $importStatement
     *        the finance seeder's own statement import, so the campaign's
     *        payments go in through exactly the pipeline the six committed
     *        statements did
     * @return array{rows: int, receivables: int, payments: int, imported: int}
     */
    public function seed(int $accountId, callable $importStatement): array
    {
        $roster = $this->roster();
        if ($roster === []) {
            return ['rows' => 0, 'receivables' => 0, 'payments' => 0, 'imported' => 0];
        }

        $path = $this->writeSpreadsheet($roster);

        try {
            $campaignId = $this->campaignService->createFromFile(
                CampaignBlueprint::LABEL,
                $this->scoutYearService->ensureYear(CampaignBlueprint::YEAR),
                $accountId,
                $path,
                CampaignBlueprint::SOURCE_FILENAME,
                Role::SUPERADMIN,
                $this->actorId,
            );
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $rows = $this->campaignService->rowsOf($campaignId);
        $lines = $this->paymentLines($rows);
        $statementPath = $this->writeStatement($lines);

        try {
            $result = $importStatement($statementPath, basename($statementPath));
        } finally {
            if (is_file($statementPath)) {
                @unlink($statementPath);
            }
        }

        return [
            'rows' => count($rows),
            'receivables' => count($this->receivableRepository->findAllByModule(CampaignService::SOURCE_MODULE)),
            'payments' => count($lines),
            'imported' => $result['imported'],
        ];
    }

    /**
     * Every member of the campaign's scout year, in Tiers order.
     *
     * Taken from the generator rather than from the database: the roster
     * needs the Tiers, the name and the section, and the first is the only
     * one the finance module will look at — but the other two are the merge
     * variables of the reminder, and reading them back out of the encrypted
     * columns to write them into an encrypted blob would be work for nothing.
     *
     * @return list<array{tiers: string, lastName: string, firstName: string, section: string}>
     */
    private function roster(): array
    {
        $roster = [];

        foreach ((new DatasetGenerator($this->datasetRoot))->people() as $tiers => $person) {
            $year = $person->years[CampaignBlueprint::YEAR] ?? null;
            if ($year === null || !isset($this->memberIds[$tiers])) {
                continue;
            }

            $roster[] = [
                'tiers' => $tiers,
                'lastName' => $person->lastName,
                'firstName' => $person->firstName,
                'section' => $year->functions[0]->section ?? "Staff d'unité",
            ];
        }

        return $roster;
    }

    /**
     * The treasurer's spreadsheet, written to a temporary file.
     *
     * @param list<array{tiers: string, lastName: string, firstName: string, section: string}> $roster
     */
    private function writeSpreadsheet(array $roster): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = array_merge(
            [CampaignBlueprint::COLUMN_DESK_ID, CampaignBlueprint::COLUMN_AMOUNT],
            CampaignBlueprint::MERGE_COLUMNS,
        );
        foreach ($headers as $column => $header) {
            $sheet->setCellValue([$column + 1, 1], $header);
        }

        foreach ($roster as $index => $member) {
            $row = $index + 2;
            // A string, not a float: the reader takes formatted values, and
            // an identifier displayed as 4821.0 is an identifier nobody
            // resolves.
            $sheet->setCellValueExplicit([1, $row], $member['tiers'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue([2, $row], number_format(CampaignBlueprint::AMOUNT_CENTS / 100, 2, ',', ''));
            $sheet->setCellValue([3, $row], $member['lastName']);
            $sheet->setCellValue([4, $row], $member['firstName']);
            $sheet->setCellValue([5, $row], $member['section']);
        }

        $path = self::temporaryPath('refdataset-campaign', 'xlsx');
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * What lands on the bank account, from the plan CampaignBlueprint
     * declares: the settled, the short, the over, the doubled — and two
     * transfers carrying a communication nobody is waiting for.
     *
     * @param list<CampaignRow> $rows
     * @return list<StatementDraft>
     */
    private function paymentLines(array $rows): array
    {
        $lines = [];
        $serial = 8000;

        foreach ($rows as $position => $row) {
            $receivable = $this->receivableRepository->findBySource(CampaignService::SOURCE_MODULE, $row->id)[0] ?? null;
            if ($receivable === null) {
                continue;
            }

            $amount = null;
            if ($position < CampaignBlueprint::PAID_IN_FULL) {
                $amount = $row->amountCents;
            } elseif (isset(CampaignBlueprint::WRONG_AMOUNTS[$position])) {
                $amount = CampaignBlueprint::WRONG_AMOUNTS[$position];
            }

            if ($amount === null) {
                // Nothing received. The majority, on purpose.
                continue;
            }

            $lines[] = $this->line($position, $amount, $receivable->communication, $serial++);

            if (in_array($position, CampaignBlueprint::DOUBLE_PAYMENTS, true)) {
                // The same transfer, made twice. The second has nothing left
                // to settle and the allocation says so rather than
                // over-paying the receivable.
                $lines[] = $this->line($position + 200, $amount, $receivable->communication, $serial++);
            }
        }

        foreach (CampaignBlueprint::ORPHAN_PAYMENTS as $index => $orphan) {
            $lines[] = $this->line(
                300 + $index,
                $orphan['amount'],
                StructuredCommunicationService::format($orphan['base']),
                $serial++,
                $orphan['from'],
            );
        }

        return $lines;
    }

    private function line(int $position, int $amountCents, string $communication, int $serial, string $counterparty = 'VIREMENT FAMILLE'): StatementDraft
    {
        $day = CampaignBlueprint::FIRST_PAYMENT_DAY + ($position % CampaignBlueprint::PAYMENT_SPREAD_DAYS);
        $date = (new \DateTimeImmutable(ExtrasBlueprint::dateIn(CampaignBlueprint::YEAR, $day)))->format('d/m/Y');

        return new StatementDraft(
            date: $date,
            valueDate: $date,
            amount: number_format($amountCents / 100, 2, ',', ''),
            transactionType: 'Virement en euros',
            counterpartyIban: '',
            counterpartyName: $counterparty,
            communication: $communication,
            // 9-prefixed serials, well clear of the 1000/2000/7000 bands
            // BankStatementBuilder uses, so no line of this file can ever be
            // mistaken for a repeat of a committed one.
            reference: sprintf('%s9%09d', (new \DateTimeImmutable($date))->format('ymd'), $serial),
        );
    }

    /**
     * @param list<StatementDraft> $lines
     */
    private function writeStatement(array $lines): string
    {
        $path = self::temporaryPath('refdataset-campaign-bank', 'csv');
        file_put_contents($path, (new BnpCsvWriter())->write(
            $lines,
            BankBlueprint::ACCOUNTS[CampaignBlueprint::ACCOUNT_HANDLE]['iban'],
            CampaignBlueprint::YEAR,
        ));

        return $path;
    }

    /**
     * A temporary path with a real extension.
     *
     * `tempnam()` alone will not do: PhpSpreadsheet's writer picks its format
     * from the extension, and appending one to a name tempnam() has already
     * created leaves an empty file behind on every build. So the reservation
     * is released as soon as the unique name is in hand.
     */
    private static function temporaryPath(string $prefix, string $extension): string
    {
        $reserved = (string) tempnam(sys_get_temp_dir(), $prefix);
        @unlink($reserved);

        return $reserved . '.' . $extension;
    }
}
