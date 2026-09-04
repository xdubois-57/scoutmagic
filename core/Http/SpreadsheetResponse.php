<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The one way a controller turns a Spreadsheet into a download.
 *
 * The pattern it replaces held three full copies of the workbook in
 * memory at once — the PhpSpreadsheet object graph, the
 * ob_start()/save('php://output') buffer, and the Response body string —
 * which put a large member export in the hundreds of megabytes of peak
 * RSS. Writing to a temp file and streaming it (Response::setBodyFile
 * with deleteAfterSend) keeps only the object graph, which
 * disconnectWorksheets() then releases before the response is sent.
 */
final class SpreadsheetResponse
{
    public static function download(Spreadsheet $spreadsheet, string $filename): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sm-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('Impossible de créer le fichier temporaire pour l\'export.');
        }

        $writer = new Xlsx($spreadsheet);
        // Every export in this codebase writes explicitly typed cells (see
        // Core\Export\TabularSpreadsheet): nothing to calculate.
        $writer->setPreCalculateFormulas(false);
        $writer->save($tmp);
        $spreadsheet->disconnectWorksheets();

        return (new Response())
            ->setBodyFile($tmp, deleteAfterSend: true)
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->setHeader('Content-Length', (string) filesize($tmp));
    }
}
