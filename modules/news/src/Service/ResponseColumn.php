<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

/**
 * One column of a form's responses, named once for every surface that
 * shows them: the XLSX export and the mail-merge variables.
 *
 * `$kind` exists for the export alone. A spreadsheet is not a string:
 * « Montant attendu » is written as a live formula so a treasurer can see
 * how it is built and adjust it, and « Montant reçu » as a real number so
 * a column of them can be summed. Every other column is text, explicitly
 * typed — a value beginning with `=` (or +, -, @) is otherwise promoted
 * to a formula by PhpSpreadsheet's default binder, and form answers are
 * submitted by anyone.
 *
 * The merge substitutes the TEXT of every column, `kind` included: a
 * variable is dropped into a sentence, and a formula in a mail to a
 * family would be nonsense.
 */
final class ResponseColumn
{
    /** Written as text, explicitly typed. */
    public const KIND_TEXT = 'text';
    /** The export writes a live sum formula in this cell. */
    public const KIND_AMOUNT_DUE = 'amount_due';
    /** The export writes a number, so a column of them can be summed. */
    public const KIND_AMOUNT_RECEIVED = 'amount_received';

    /**
     * @param string $label the header, and the merge variable's name
     * @param string $source what the value is read from — see
     *        `ResponseColumns::valueFor()`, which is the only reader
     * @param ?int $fieldId the form field this column carries, for a
     *        column that is one (null for every other source)
     * @param ?string $fieldType that field's type, so a switch can read
     *        « Oui »/« Non » rather than the 1/0 it is stored as — in a
     *        spreadsheet as much as in a mail to a family
     */
    public function __construct(
        public readonly string $label,
        public readonly string $source,
        public readonly ?int $fieldId = null,
        public readonly string $kind = self::KIND_TEXT,
        public readonly ?string $fieldType = null
    ) {
    }
}
