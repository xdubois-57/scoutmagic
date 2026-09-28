<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The rows of a picker's result list never shrink below their content
 * (issue #645).
 *
 * The three pickers — search, stay, receivable — hang a Bootstrap
 * `list-group` below their field: a flex column with a `max-height` and its
 * own scroll. When the rows add up to more than that height, WebKit shrinks
 * them to fit rather than letting the list scroll: on an iPhone every row
 * came out the same, too short height, the date line cut off by the next
 * row. Chrome and Firefox keep `min-height: auto` and never showed it —
 * which is also why the end-to-end suite, Chromium only, cannot: this test
 * holds the rule instead of a render.
 */
final class PickerResultsKeepTheirHeightTest extends TestCase
{
    private const LISTS = ['.search-picker__results', '.stay-picker__results', '.receivable-picker__results'];

    public function testEveryPickerListKeepsItsRowsFromShrinking(): void
    {
        $rules = self::rules((string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/components.css'));

        foreach (self::LISTS as $list) {
            $this->assertTrue(
                self::someRuleSets($rules, $list . ' > *', 'flex-shrink', '0'),
                "$list > * must set flex-shrink: 0, or WebKit squeezes its rows once the list overflows"
            );
        }
    }

    /** The reader finds a declaration in a selector list, and not in a lookalike. */
    public function testTheReaderKnowsARuleWhenItSeesOne(): void
    {
        $rules = self::rules("/* .a > * { flex-shrink: 0 } */\n.a > *,\n.b > * {\n    flex-shrink: 0;\n}\n.c > * { flex-shrink: 1; }");

        $this->assertTrue(self::someRuleSets($rules, '.a > *', 'flex-shrink', '0'));
        $this->assertTrue(self::someRuleSets($rules, '.b > *', 'flex-shrink', '0'));
        $this->assertFalse(self::someRuleSets($rules, '.c > *', 'flex-shrink', '0'));
        $this->assertFalse(self::someRuleSets($rules, '.d > *', 'flex-shrink', '0'));
    }

    /**
     * @return list<array{selectors: list<string>, body: string}>
     */
    private static function rules(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $match) {
            $rules[] = [
                'selectors' => array_map(
                    static fn(string $selector): string => (string) preg_replace('/\s+/', ' ', trim($selector)),
                    explode(',', $match[1])
                ),
                'body' => $match[2],
            ];
        }

        return $rules;
    }

    /**
     * @param list<array{selectors: list<string>, body: string}> $rules
     */
    private static function someRuleSets(array $rules, string $selector, string $property, string $value): bool
    {
        foreach ($rules as $rule) {
            if (
                in_array($selector, $rule['selectors'], true)
                && preg_match('/(?:^|;)\s*' . preg_quote($property, '/') . '\s*:\s*' . preg_quote($value, '/') . '\s*(?:;|$)/', $rule['body']) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
