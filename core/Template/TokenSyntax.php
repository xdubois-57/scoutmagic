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
        private readonly string $modifiers,
        private readonly bool $decodeBareNames = false
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
        // Percent bytes cannot be literal identifiers, so their encoded
        // spelling has no competing name in this closed vocabulary.
        return new self('[a-z0-9_]+', '', true);
    }

    /**
     * Candidate tokens, retaining their original opening, name and closing.
     * Literal delimiters take precedence: percent bytes inside a literal
     * column name must never be mistaken for encoded closing delimiters.
     * The vocabulary is checked after decoding an actually encoded token.
     */
    public function pattern(): string
    {
        return '/(?|(?<opening>\{\{)(?<name>[^{}]*?)(?<closing>\}\})'
            . '|(?<opening>(?:%7[bB]|\{){2})(?<name>[^{}]*?)(?<closing>(?:%7[dD]|\}){2}))/'
            . $this->modifiers;
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
     * Decode once for lookup when the delimiters prove encoding, or when
     * the identifier vocabulary rules out a literal percent name entirely.
     * A spreadsheet header such as "Code %41" is already its real name.
     * Unknown encoded names are never inserted back into HTML.
     */
    public function decodeName(string $storedName, bool $encoded): ?string
    {
        $name = $encoded || $this->decodeBareNames ? rawurldecode($storedName) : $storedName;

        return preg_match($this->nameOnlyPattern(), $name) === 1 ? $name : null;
    }
}
