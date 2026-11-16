<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

use Core\File\PdfTextExtractor;

/**
 * The PDF half: extract the text layer, then hand it to
 * {@see InvoiceParser}.
 *
 * Kept apart from the parser on purpose. Every rule the parser enforces is
 * about text, and a rule that can only be exercised through a binary file
 * is a rule nobody adds a case to. `Core\File\PdfTextExtractor` is the
 * codebase's one text-layer reader (`smalot/pdfparser`, already a
 * dependency — this feature adds none).
 *
 * A scanned invoice has no text layer and is refused as such rather than
 * read as an empty one: there is no OCR path here, and inventing a total
 * from an image is not something this feature would ever be allowed to do.
 *
 * **The one assumption worth stating**, because it is what a real document
 * would break first: the parser reads one ROW per extracted line. A real
 * federation invoice (#1327) confirmed it — one row per line — but showed
 * the cells of a row run together, a tab before the numbers, and the
 * amount glued to the unit price; the parser reads that layout as well
 * ({@see InvoiceParser}). A reporting tool that put every cell on a line
 * of its own would still be refused loudly rather than half read, and the
 * reading statistics journaled with the refusal would say so.
 */
class InvoiceReader
{
    public function __construct(
        private PdfTextExtractor $extractor,
        private InvoiceParser $parser
    ) {
    }

    public function read(string $pdfContent): InvoiceReadResult
    {
        try {
            $text = $this->extractor->extractText($pdfContent);
        } catch (\Throwable) {
            // The extractor already turns the library's own failures into
            // null; this is the net under it. Nothing of the exception is
            // kept: its text can quote the document (#1327).
            return InvoiceReadResult::refused([new InvoiceProblem(
                InvoiceProblem::EXTRACTION_FAILED,
                "Ce PDF n'a pas pu être lu. Vérifiez qu'il s'agit bien du fichier envoyé par la fédération, "
                . 'non modifié.'
            )]);
        }
        if ($text === null) {
            return InvoiceReadResult::refused([new InvoiceProblem(
                InvoiceProblem::NO_TEXT_LAYER,
                "Ce PDF ne contient pas de texte lisible : il s'agit probablement d'un document scanné. "
                . 'Demandez à la fédération le PDF d\'origine.'
            )]);
        }

        return $this->parser->parse($text);
    }
}
