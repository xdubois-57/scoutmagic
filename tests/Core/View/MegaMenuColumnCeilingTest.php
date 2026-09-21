<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * **The widest menu's column count, written down in three places, kept
 * in agreement.**
 *
 * `.desktop-megamenu-grid` is a `repeat(auto-fit, minmax(200px, 1fr))`
 * grid, so it does not enforce a ceiling — it adapts. What it does carry
 * is a stated one, in its own comment and in ARCHITECTURE.md §11, and
 * both said « at most four » while MENU_GROUPS had grown to six. Nothing
 * failed, because a CSS comment cannot fail: the layout kept working and
 * the documentation quietly became false.
 *
 * That is the whole reason this test exists. A number a reader trusts,
 * that no test holds, drifts — and the drift is invisible precisely
 * because the thing it describes still works. So the number is asserted
 * against the real declaration rather than against a literal, and both
 * documents are read for it.
 *
 * Reading the sources by pattern is the same technique
 * Tests\Core\View\ServiceWorkerPrecacheTest already uses, and for the
 * same reason: neither a stylesheet nor a Markdown file can be loaded
 * and asked.
 */
final class MegaMenuColumnCeilingTest extends TestCase
{
    /** The widest column count MENU_GROUPS actually declares. */
    private static function widest(): int
    {
        return max(array_map('count', MenuBuilder::MENU_GROUPS));
    }

    /**
     * A menu that outgrows the number the documents state is not an
     * error in itself — it is a decision to take, with the layout in
     * mind, and then to write down. This test is where it is taken.
     */
    public function testTheStylesheetNamesTheNumberOfColumnsReallyDeclared(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');

        $this->assertStringContainsString(
            'at most ' . self::spelled(self::widest()) . ', the widest',
            $css,
            'public/assets/css/app.css states a column ceiling MENU_GROUPS no longer matches.'
        );
    }

    public function testTheArchitectureStatesTheSameNumber(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/ARCHITECTURE.md');

        $this->assertStringContainsString(
            'One column per declared group, at most ' . self::spelled(self::widest()) . ',',
            $doc,
            'ARCHITECTURE.md §11 states a column ceiling MENU_GROUPS no longer matches.'
        );
    }

    /**
     * The grid's minimum track is 200px and its gap 2rem, so a row of
     * columns needs `n * 200 + (n - 1) * 32` pixels before auto-fit wraps
     * it. Stated here so that a future column arrives with its cost
     * visible: at seven the widest menu needs 1592px, which is past the
     * laptops this site is used on, and the row would wrap.
     */
    public function testTheWidestMenuStillFitsALaptopRow(): void
    {
        $n = self::widest();
        $needed = ($n * 200) + (($n - 1) * 32);

        $this->assertLessThanOrEqual(
            1400,
            $needed,
            "The widest menu now declares {$n} columns, needing {$needed}px before the mega-menu row wraps. "
                . 'Wrapping is a graceful degradation, not a break — but it is a layout decision, so take it '
                . 'deliberately and move this bound rather than deleting the test.'
        );
    }

    /** English number words, for the two documents that spell it out. */
    private static function spelled(int $n): string
    {
        $words = [1 => 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight'];

        self::assertArrayHasKey($n, $words, "No spelling for {$n} columns.");

        return $words[$n];
    }
}
