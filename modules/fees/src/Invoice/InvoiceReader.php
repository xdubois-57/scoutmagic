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
 * would break first: the parser reads one ROW per extracted line. That
 * holds for a PDF whose generator writes each row as one text-showing
 * operation, which is what the fixture reproduces. A reporting tool that
 * positions every CELL separately can make the extractor emit one line per
 * cell instead — and then nothing below recognises anything, loudly (no
 * tariff line found), rather than reading half a document. The fix, if a
 * real invoice turns out to be that shape, is a line-reassembly step HERE,
 * grouping cells back into rows before the text reaches the parser; none of
 * the parser's six rules changes, which is the reason the two are separate
 * classes in the first place.
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
        $text = $this->extractor->extractText($pdfContent);
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
