<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use setasign\Fpdi\Fpdi;

/**
 * Cuts one certificate out of the deposited file: given a page range, it
 * returns a PDF holding exactly those pages.
 *
 * **Keeping the whole file was never an option.** Handing a family the
 * federation's PDF would hand them every other family's certificate — which
 * is the entire reason this module exists rather than an « envoyer le PDF »
 * button. No dependency already here can cut a PDF: `dompdf` renders from
 * HTML, `smalot/pdfparser` reads text. FPDI is what closes that gap
 * (ARCHITECTURE.md §1).
 *
 * Like the reader, it touches no database and writes no file: it is handed
 * a path and a range and returns bytes. The caller decides where they go —
 * which in this module is always Core\File\EncryptedFileStorageService with
 * `owner_member_id` set, never a plain path on disk.
 *
 * Each page keeps its own size and orientation. A federation template is
 * A4 portrait throughout, but reading the imported page's own box costs
 * nothing and is what stops a landscape annexe coming out cropped.
 */
class AttestationPdfSplitter
{
    /**
     * @param int $firstPage 1-based, inclusive
     * @param int $lastPage  1-based, inclusive
     *
     * @return string the extracted PDF's bytes
     *
     * @throws AttestationsException when the range is not inside the file,
     *                               or the file cannot be imported
     */
    public function extract(string $pdfPath, int $firstPage, int $lastPage): string
    {
        if ($firstPage < 1 || $lastPage < $firstPage) {
            throw new AttestationsException(
                'Le découpage demandé ne correspond à aucune page du fichier. Reprenez le lot depuis le dépôt.'
            );
        }

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($pdfPath);

            if ($lastPage > $pageCount) {
                throw new AttestationsException(
                    'Le découpage demandé dépasse le nombre de pages du fichier. Reprenez le lot depuis le dépôt.'
                );
            }

            for ($page = $firstPage; $page <= $lastPage; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            return (string) $pdf->Output('S');
        } catch (AttestationsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // FPDI and FPDF both speak English and name internals; neither
            // message may reach a chef d'unité (AGENTS.md § Exception
            // messages that reach a visitor). The detail rides on
            // $previous, for the journal.
            throw new AttestationsException(
                'Ce PDF n\'a pas pu être découpé. S\'il est protégé par un mot de passe ou signé électroniquement, '
                . 'demandez à la fédération une version ordinaire.',
                0,
                $e
            );
        }
    }
}
