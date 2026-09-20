<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Template\TokenEngine;
use Core\Template\TokenSyntax;

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
 *
 * **The token machinery itself is Core\Template\TokenEngine** — the
 * pattern, the percent-encoded rescue, the unknown-token report and the
 * escaping at substitution, all of it shared with the rental module's
 * document keywords, which had grown its own copy of the same four rules.
 * What stays here is what is only true of a publipostage: the catalogue is
 * a spreadsheet's column headers, and the sections below.
 */
class MergeRenderer
{
    /**
     * {{#Colonne}} … {{/Colonne}}. The closing marker has to name the
     * same column, spelled the same way — the opening name is captured
     * trimmed and back-referenced, so a mismatched pair simply does not
     * match and stays visible in the preview.
     */
    private const SECTION_PATTERN = '/\{\{\s*#\s*([^{}#\/]*?)\s*\}\}(.*?)\{\{\s*\/\s*\1\s*\}\}/us';

    private readonly TokenEngine $engine;

    public function __construct()
    {
        // Free text: a token names a column header — « Prénom 1 »,
        // « Référence du billet » — with spaces and accents, chosen by
        // whoever built the file.
        $this->engine = new TokenEngine(TokenSyntax::freeText());
    }

    /**
     * Whether this template personalises anything at all.
     *
     * A publipostage's subject very often carries no variable — the
     * variables live in the body — and then every recipient's subject is
     * the same string. Callers that must not handle per-recipient values
     * (Task\SendBatchHandler's notification, whose store outlives the
     * merge retention) use this to tell the two cases apart instead of
     * assuming a merge always personalises.
     */
    public function containsToken(string $template): bool
    {
        return $this->engine->containsToken($template);
    }

    /**
     * @param array<string, string> $data {header: value}
     */
    public function renderHtml(string $template, array $data): string
    {
        $template = $this->engine->decodeEncodedTokens($template);

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
        $template = $this->engine->decodeEncodedTokens($template);

        return $this->render($this->resolveSections($template, $data), $data, false);
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
        $known = [];
        foreach ($columns as $column) {
            $known[mb_strtolower(trim($column))] = true;
        }

        return $this->engine->unknownTokens(
            $this->engine->decodeEncodedTokens($template),
            static fn(string $name): bool => isset($known[mb_strtolower(trim($name))]),
            // A section marker names its column with a leading # or /: the
            // column is what a typo is about, so that is what gets
            // reported.
            static fn(string $name): string => ltrim($name, '#/ ')
        );
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
        $template = $this->resolveSections($this->engine->decodeEncodedTokens($template), $data);

        $byLower = [];
        foreach ($data as $column => $value) {
            $byLower[mb_strtolower(trim($column))] = ['name' => $column, 'value' => $value];
        }

        $missing = [];
        foreach ($this->engine->rawTokenNames($template) as $name) {
            $entry = $byLower[mb_strtolower(trim($name))] ?? null;
            if ($entry !== null && trim($entry['value']) === '') {
                $missing[$entry['name']] = true;
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

        return $this->engine->substitute(
            $template,
            static function (string $name) use ($byLower): ?string {
                $key = mb_strtolower($name);

                return array_key_exists($key, $byLower) ? $byLower[$key] : null;
            },
            $escapeHtml
        );
    }
}
