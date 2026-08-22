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
 */
class MergeRenderer
{
    private const TOKEN_PATTERN = '/\{\{\s*([^{}]*?)\s*\}\}/u';

    /**
     * @param array<string, string> $data {header: value}
     */
    public function renderHtml(string $template, array $data): string
    {
        return $this->render($template, $data, true);
    }

    /**
     * Plain-text context (the subject) — no HTML escaping, the value is
     * used verbatim as text.
     *
     * @param array<string, string> $data
     */
    public function renderText(string $template, array $data): string
    {
        return $this->render($template, $data, false);
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

        $unknown = [];
        if (preg_match_all(self::TOKEN_PATTERN, $template, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (!isset($known[mb_strtolower(trim((string) $name))])) {
                    $unknown[trim((string) $name)] = true;
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
