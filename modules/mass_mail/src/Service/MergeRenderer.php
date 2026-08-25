<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

/**
 * Substitutes a mail-merge row's values into an email's subject/body.
 * Tokens are {{Colonne}} — the exact column header, matched
 * case-insensitively with surrounding whitespace tolerated.
 *
 * In the HTML body every substituted value is ALWAYS escaped: the values
 * come from a chief-uploaded Excel file, i.e. arbitrary input — without
 * escaping, a cell containing markup would be injected verbatim into the
 * email HTML that HtmlSanitizer already cleaned at save time. A token
 * naming no column is left untouched (visible in the test preview,
 * flagged by findUnknownTokens(), never silently swallowed).
 *
 * **Sections — {{#Colonne}} … {{/Colonne}} — keep the enclosed markup
 * only for the rows whose value for that column is filled.** They exist
 * because a personalised body is not always the same LENGTH for
 * everybody: a payment reminder carries one autonomous block per
 * receivable, and a household with one child must not receive the two
 * empty blocks the household with three needs. Escaping makes the
 * obvious alternative impossible on purpose — a whole block cannot be
 * passed in as one HTML value — so the variable part has to be
 * expressible in the template itself.
 *
 * Sections are resolved BEFORE any substitution, are not nested (an
 * inner one is left as text rather than half-understood), and a section
 * naming no column is left untouched exactly like an unknown token: the
 * preview shows it and findUnknownTokens() reports it, rather than the
 * block disappearing without a word.
 */
class MergeRenderer
{
    private const TOKEN_PATTERN = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    /**
     * {{#Colonne}} … {{/Colonne}}. The closing marker has to name the
     * same column, spelled the same way — the opening name is captured
     * trimmed and back-referenced, so a mismatched pair simply does not
     * match and stays visible in the preview.
     */
    private const SECTION_PATTERN = '/\{\{\s*#\s*([^{}#\/]*?)\s*\}\}(.*?)\{\{\s*\/\s*\1\s*\}\}/us';

    /**
     * A token that ended up inside an href or a src comes back
     * percent-encoded: the rich-text sanitizer parses the body with
     * DOMDocument, which URL-encodes every URI attribute on the way out,
     * so `{{QR 1}}` is stored as `%7B%7BQR%201%7D%7D`. Left alone, the
     * variable would simply never substitute and the recipient would get
     * a broken link — silently, which is the worst of both. Recognised
     * here rather than "fixed" in the sanitizer, whose encoding is
     * correct for every other URL it handles.
     */
    private const ENCODED_TOKEN_PATTERN = '/%7B%7B(.*?)%7D%7D/i';

    /**
     * @param array<string, string> $data {header: value}
     */
    public function renderHtml(string $template, array $data): string
    {
        $template = self::decodeUrlEncodedTokens($template);

        return $this->render($this->resolveSections($template, $data), $data, true);
    }

    /**
     * Plain-text context (the subject) — no HTML escaping, the value is
     * used verbatim as text.
     *
     * @param array<string, string> $data
     */
    public function renderText(string $template, array $data): string
    {
        $template = self::decodeUrlEncodedTokens($template);

        return $this->render($this->resolveSections($template, $data), $data, false);
    }

    /**
     * Rewrites `%7B%7BNom%20de%20colonne%7D%7D` back to
     * `{{Nom de colonne}}` so the rest of this class sees one shape.
     */
    private static function decodeUrlEncodedTokens(string $template): string
    {
        return (string) preg_replace_callback(
            self::ENCODED_TOKEN_PATTERN,
            static fn(array $matches): string => '{{' . rawurldecode($matches[1]) . '}}',
            $template
        );
    }

    /**
     * Keeps each section's body when its column has a value for this row,
     * drops it when the column is present and empty, and leaves the whole
     * thing alone when the column does not exist at all.
     *
     * One pass, deliberately: a second pass over the result would loop
     * for ever on an untouched unknown section, and nesting is out of
     * scope (see the class docblock).
     *
     * @param array<string, string> $data
     */
    private function resolveSections(string $template, array $data): string
    {
        $byLower = [];
        foreach ($data as $column => $value) {
            $byLower[mb_strtolower(trim($column))] = $value;
        }

        return (string) preg_replace_callback(
            self::SECTION_PATTERN,
            static function (array $matches) use ($byLower): string {
                $key = mb_strtolower(trim($matches[1]));
                if (!array_key_exists($key, $byLower)) {
                    return $matches[0];
                }

                return trim($byLower[$key]) !== '' ? $matches[2] : '';
            },
            $template
        );
    }

    /**
     * Tokens present in $template that match none of $columns — surfaced
     * as a warning in the compose dialog's test preview (a typo in a
     * variable name would otherwise reach every recipient as literal
     * "{{Prénon}}" text).
     *
     * @param string[] $columns
     * @return string[]
     */
    public function findUnknownTokens(string $template, array $columns): array
    {
        $template = self::decodeUrlEncodedTokens($template);

        $known = [];
        foreach ($columns as $column) {
            $known[mb_strtolower(trim($column))] = true;
        }

        $unknown = [];
        if (preg_match_all(self::TOKEN_PATTERN, $template, $matches) > 0) {
            foreach ($matches[1] as $name) {
                // A section marker names its column with a leading # or
                // /: the column is what a typo is about, so that is what
                // gets reported.
                $name = ltrim(trim((string) $name), '#/ ');
                if ($name === '') {
                    continue;
                }
                if (!isset($known[mb_strtolower(trim($name))])) {
                    $unknown[trim($name)] = true;
                }
            }
        }
        return array_keys($unknown);
    }

    /**
     * Column names whose value is empty for this row and which the
     * template actually uses — the test preview's "3 lignes sans valeur
     * pour Montant" style warning is built from this.
     *
     * @param array<string, string> $data
     * @return string[]
     */
    public function findMissingValues(string $template, array $data): array
    {
        // A column used only to open a section is EXPECTED to be empty
        // for some rows — that is what the section is for — so the
        // sections are resolved first and only what survives is checked.
        $template = $this->resolveSections(self::decodeUrlEncodedTokens($template), $data);

        $missing = [];
        if (preg_match_all(self::TOKEN_PATTERN, $template, $matches) > 0) {
            $byLower = [];
            foreach ($data as $column => $value) {
                $byLower[mb_strtolower(trim($column))] = ['name' => $column, 'value' => $value];
            }
            foreach ($matches[1] as $name) {
                $entry = $byLower[mb_strtolower(trim((string) $name))] ?? null;
                if ($entry !== null && trim($entry['value']) === '') {
                    $missing[$entry['name']] = true;
                }
            }
        }
        return array_keys($missing);
    }

    /**
     * @param array<string, string> $data
     */
    private function render(string $template, array $data, bool $escapeHtml): string
    {
        $byLower = [];
        foreach ($data as $column => $value) {
            $byLower[mb_strtolower(trim($column))] = $value;
        }

        return (string) preg_replace_callback(self::TOKEN_PATTERN, function (array $matches) use ($byLower, $escapeHtml): string {
            $key = mb_strtolower(trim($matches[1]));
            if (!array_key_exists($key, $byLower)) {
                return $matches[0];
            }
            $value = $byLower[$key];
            return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES) : $value;
        }, $template);
    }
}
