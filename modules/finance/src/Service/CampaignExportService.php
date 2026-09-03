<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Repository\Campaign;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A campaign's lines as a spreadsheet — **exactly the lines on screen**,
 * filter included.
 *
 * Its own columns rather than the core member export's: this is finance
 * data about people, not a member export, and
 * Core\Member\Export\MemberExportColumns says in so many words that a
 * module puts its own data in its own sheet instead of extending that
 * list.
 *
 * The treasurers' note IS in here and is deliberately never a merge
 * variable of the reminder: an export is read by the treasurers, a
 * reminder by the family, and a note that could end up in a reminder is
 * a note nobody would write honestly.
 *
 * Every textual cell is written with an explicit string type, so a value
 * beginning with `=`, `+`, `-` or `@` cannot become a live formula in
 * the treasurer's spreadsheet (SECURITY.md — formula injection). It
 * matters more here than almost anywhere: a communication and a note are
 * free text, and a member's name comes from an import this site did not
 * write.
 */
class CampaignExportService
{
    private const COLUMNS = [
        'Nom',
        'Prénom',
        'Section',
        'Communication',
        'Montant dû',
        'Montant reçu',
        'Reste à payer',
        'Trop-perçu',
        'Statut',
        'Note interne',
        'Note — auteur',
        'Note — mise à jour',
    ];

    private const STATUS_LABELS = [
        ReceivableSettlement::STATUS_PAID => 'Payée',
        ReceivableSettlement::STATUS_PARTIAL => 'Partielle',
        ReceivableSettlement::STATUS_UNPAID => 'Impayée',
        ReceivableSettlement::STATUS_WAIVED => 'Abandonnée',
    ];

    /**
     * The serialized workbook — kept for callers/tests that want the
     * bytes; the download route streams buildSpreadsheet() through
     * Core\Http\SpreadsheetResponse instead, which avoids ever holding
     * the file as a PHP string.
     *
     * @param array<int, array<string, mixed>> $rows as built by Service\CampaignOverviewService::detail()
     */
    public function build(Campaign $campaign, array $rows): string
    {
        $spreadsheet = $this->buildSpreadsheet($campaign, $rows);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $output = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return $output;
    }

    /**
     * @param array<int, array<string, mixed>> $rows as built by Service\CampaignOverviewService::detail()
     */
    public function buildSpreadsheet(Campaign $campaign, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($campaign->label));

        foreach (self::COLUMNS as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }

        $line = 2;
        foreach ($rows as $row) {
            $sheet->setCellValueExplicit([1, $line], (string) ($row['last_name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $line], (string) ($row['first_name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $line], (string) ($row['section'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $line], (string) ($row['communication'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $line], self::euros($row['amount_due'] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit([6, $line], self::euros($row['amount_received'] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit(
                [7, $line],
                self::euros($row['amount_remaining'] ?? 0),
                DataType::TYPE_NUMERIC
            );
            $sheet->setCellValueExplicit([8, $line], self::euros($row['amount_overpaid'] ?? 0), DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit([9, $line], self::STATUS_LABELS[$row['status'] ?? ''] ?? '',
                DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([10, $line], (string) ($row['note'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([11, $line], (string) ($row['note_author'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([12, $line], (string) ($row['note_updated_at'] ?? ''), DataType::TYPE_STRING);
            $line++;
        }

        $sheet->freezePane('A2');

        return $spreadsheet;
    }

    private static function euros(mixed $cents): float
    {
        return round(((int) $cents) / 100, 2);
    }

    /**
     * Excel refuses a handful of characters in a sheet name and truncates
     * at 31 — a campaign label is free text, so it is cleaned rather than
     * trusted.
     */
    private function sheetTitle(string $label): string
    {
        $clean = trim((string) preg_replace('/[\\\\\\/\\*\\?\\[\\]:]/u', ' ', $label));

        return $clean === '' ? 'Campagne' : mb_substr($clean, 0, 31);
    }
}
