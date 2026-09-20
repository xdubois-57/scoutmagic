<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Template;

/**
 * What a `{{ … }}` token looks like, for one substitution catalogue.
 *
 * Two engines in this codebase substitute `{{ … }}` and they do NOT agree
 * on what may sit between the braces, so the shape is a parameter rather
 * than a constant:
 *
 * - a mail-merge token names a column header of an uploaded spreadsheet —
 *   « Prénom 1 », « Référence du billet » — free text with spaces and
 *   accents, chosen by whoever built the file (`self::freeText()`);
 * - a rental document keyword names an entry of a closed, declared list —
 *   `prix_total` — and the narrowness is what makes "unknown keyword"
 *   reporting useful at all, because otherwise every stray `{{` in a
 *   contract's prose becomes a candidate (`self::identifiers()`).
 *
 * The `u` modifier travels with the shape for the same reason: the free
 * text one matches accented column names and needs it, the identifier one
 * matches ASCII only and has never carried it. Neither is "more correct";
 * they are two different vocabularies and this object keeps each exactly
 * as its engine had it.
 */
final class TokenSyntax
{
    /**
     * @param string $namePattern A regex fragment matching what sits between the braces,
     *                            with exactly one capturing group's worth of meaning and none of its own.
     * @param string $modifiers   Regex modifiers, appended to every pattern built here.
     */
    private function __construct(
        private readonly string $namePattern,
        private readonly string $modifiers
    ) {
    }

    /**
     * Anything but a brace: a spreadsheet column header.
     */
    public static function freeText(): self
    {
        return new self('[^{}]*?', 'u');
    }

    /**
     * Lower-case letters, digits and underscores: a declared keyword.
     */
    public static function identifiers(): self
    {
        return new self('[a-z0-9_]+', '');
    }

    /**
     * `{{ name }}`, with any spacing, capturing the name in group 1.
     */
    public function pattern(): string
    {
        return '/\{\{\s*(' . $this->namePattern . ')\s*\}\}/' . $this->modifiers;
    }

    /**
     * The same, anchored: does this whole string spell one token's name?
     *
     * Used by the repair pass, which only rewrites a region once what it
     * would become is a real token rather than a guess.
     */
    public function nameOnlyPattern(): string
    {
        return '/^\s*(' . $this->namePattern . ')\s*$/' . $this->modifiers;
    }

    /**
     * A token that ended up inside an `href` or a `src` comes back
     * percent-encoded: the rich-text sanitizer parses the body with
     * DOMDocument, which URL-encodes every URI attribute on the way out,
     * so `{{QR 1}}` is stored as `%7B%7BQR%201%7D%7D`. Left alone, the
     * variable would simply never substitute and the recipient would get a
     * broken link — silently, which is the worst of both. Recognised here
     * rather than "fixed" in the sanitizer, whose encoding is correct for
     * every other URL it handles.
     *
     * Deliberately not parameterised by the name pattern: what has to be
     * recognised is the encoding, and a name that decodes to something the
     * catalogue does not know is reported as unknown afterwards like any
     * other.
     */
    public function encodedPattern(): string
    {
        return '/%7B%7B(.*?)%7D%7D/i';
    }
}
