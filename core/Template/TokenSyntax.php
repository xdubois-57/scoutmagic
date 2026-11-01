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
     * @param string|null $encodedNamePattern The stored spelling, when encoded bytes need wider matching.
     */
    private function __construct(
        private readonly string $namePattern,
        private readonly string $modifiers,
        private readonly ?string $encodedNamePattern = null
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
        return new self('[a-z0-9_]+', '', '(?:[a-z0-9_]|%[a-fA-F0-9]{2})+');
    }

    /**
     * `{{ name }}`, with any spacing, capturing the name in group 1.
     */
    public function pattern(): string
    {
        return '/\{\{\s*(' . ($this->encodedNamePattern ?? $this->namePattern) . ')\s*\}\}/' . $this->modifiers;
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
     * Decode once for lookup, never for insertion back into HTML. Unknown
     * names may contain encoded markup or quotes; their stored spelling
     * must stay encoded. Validation still uses the original vocabulary.
     */
    public function decodeName(string $storedName): ?string
    {
        $name = rawurldecode($storedName);
        // A literal spreadsheet header can contain "%FF". Decoding it
        // must not turn valid text into invalid UTF-8 and hide the token.
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = $storedName;
        }

        return preg_match($this->nameOnlyPattern(), $name) === 1 ? $name : null;
    }

    /**
     * Old stored templates and pasted URLs can encode braces and spaces
     * independently. Decode only token regions, never an entire URL.
     * The HTML5 sanitizer preserves literal braces in new templates.
     */
    public function encodedPattern(): string
    {
        return '/(?:%7B|\{){2}(.*?)(?:%7D|\}){2}/i';
    }
}
