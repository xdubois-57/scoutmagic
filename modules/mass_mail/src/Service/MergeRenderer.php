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
 * everybody: a line of the file may fill an optional paragraph — a
 * second contact person, a remaining balance — that another line leaves
 * empty, and the line that leaves it empty must not receive it blank.
 * Escaping makes the
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
     * same column, spelled the same way after one decoding pass. Neither
     * name is decoded back into the HTML. Encoded whitespace before a
     * marker is accepted too, as in historical URL-encoded templates.
     */
    private const SECTION_SPACE = '(?:\s|%20|%09|%0[a-dA-D])*';
    private const SECTION_MARKER_PATTERN = '/\{\{' . self::SECTION_SPACE
        . '(#|%23|\/|%2[fF])([^{}#\/]*)\}\}/u';

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
     * @param array<array-key, string> $data {header: value}; PHP casts numeric headers to integer keys
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
     * @param array<array-key, string> $data
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
     * @param array<array-key, string> $data
     */
    private function resolveSections(string $template, array $data): string
    {
        $byLower = [];
        foreach ($data as $column => $value) {
            $byLower[mb_strtolower(trim((string) $column))] = $value;
        }

        [$markers, $closingIndexes] = $this->sectionMarkers($template);
        $closingPositions = [];
        $cursor = 0;
        $rendered = '';
        $count = count($markers);
        for ($index = 0; $index < $count; $index++) {
            $opening = $markers[$index];
            if (!$opening['opening']) {
                continue;
            }
            $name = $opening['name'];
            $closings = $closingIndexes[$name] ?? [];
            $position = $closingPositions[$name] ?? 0;
            while (isset($closings[$position]) && $closings[$position] <= $index) {
                $position++;
            }
            $closingPositions[$name] = $position;
            $closingIndex = $closings[$position] ?? null;
            if ($closingIndex === null) {
                // Leave a stray opener visible, then try the next opener.
                continue;
            }
            $closingPositions[$name] = $position + 1;
            $closing = $markers[$closingIndex];
            $bodyStart = $opening['start'] + $opening['length'];
            $end = $closing['start'] + $closing['length'];
            $rendered .= substr($template, $cursor, $opening['start'] - $cursor);
            $rendered .= $this->resolveSection(
                substr($template, $opening['start'], $end - $opening['start']),
                substr($template, $bodyStart, $closing['start'] - $bodyStart),
                $name,
                $byLower
            );
            $cursor = $end;
            // The outer section owns its whole body: never resolve inner
            // section markers in a second pass through the same block.
            $index = $closingIndex;
        }

        return $rendered . substr($template, $cursor);
    }

    /**
     * Names are decoded only as lookup keys; output always uses stored bytes.
     * Indexing closing markers avoids repeatedly scanning an unmatched tail.
     *
     * @return array{
     *   list<array{name: string, opening: bool, start: int, length: int}>,
     *   array<array-key, list<int>>
     * }
     */
    private function sectionMarkers(string $template): array
    {
        preg_match_all(self::SECTION_MARKER_PATTERN, $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $syntax = TokenSyntax::freeText();
        $markers = [];
        $closings = [];
        foreach ($matches as $match) {
            $name = $syntax->decodeName($match[2][0]);
            if ($name === null || str_contains($name, '#') || str_contains($name, '/')) {
                continue;
            }
            $name = trim($name);
            $opening = $match[1][0] === '#' || $match[1][0] === '%23';
            $index = count($markers);
            $markers[] = [
                'name' => $name,
                'opening' => $opening,
                'start' => $match[0][1],
                'length' => strlen($match[0][0]),
            ];
            if (!$opening) {
                $closings[$name][] = $index;
            }
        }

        return [$markers, $closings];
    }

    /** @param array<array-key, string> $byLower */
    private function resolveSection(string $section, string $body, string $name, array $byLower): string
    {
        $key = mb_strtolower($name);
        if (!array_key_exists($key, $byLower)) {
            return $section;
        }

        return trim($byLower[$key]) !== '' ? $body : '';
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
     * @param array<array-key, string> $data
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
            $byLower[mb_strtolower(trim((string) $column))] = ['name' => (string) $column, 'value' => $value];
        }

        $missing = [];
        foreach ($this->engine->rawTokenNames($template) as $name) {
            $entry = $byLower[mb_strtolower(trim($name))] ?? null;
            if ($entry !== null && trim($entry['value']) === '') {
                $missing[$entry['name']] = true;
            }
        }

        return array_map('strval', array_keys($missing));
    }

    /**
     * @param array<array-key, string> $data
     */
    private function render(string $template, array $data, bool $escapeHtml): string
    {
        $byLower = [];
        foreach ($data as $column => $value) {
            $byLower[mb_strtolower(trim((string) $column))] = $value;
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
