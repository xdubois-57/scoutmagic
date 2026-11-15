<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Api;

/**
 * One question of an imported form, as {@see NewsImportWriterInterface}
 * writes it: what was asked, never what was answered.
 */
final class ImportedFormField
{
    /**
     * @param string $type one of {@see NewsImportWriterInterface::FIELD_TYPES}
     * @param ?string $label the question; required for a type that asks
     *        something, plain text without `<` or `>`, at most 255 characters
     * @param list<string> $options the choices of an option type, one line each
     * @param ?string $content the HTML of a `text` block, the sentence of a `confirmation`
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $label,
        public readonly bool $required,
        public readonly array $options = [],
        public readonly ?string $content = null
    ) {
    }
}
