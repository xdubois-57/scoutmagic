<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/chip-picker.js — no JS test runner in this repo
 * (AGENTS.md), so this reads the raw file structurally, same precedent as
 * tests/Core/View/BreadcrumbJsTest.php. Covers window.ChipPicker.setSelected,
 * the escape hatch mode:multi callers (e.g. chefs/staffs.html.twig's badge
 * picker) use to revert an optimistic toggle after their own persistence
 * call is rejected by the server, without re-triggering their own
 * chip-picker:change listener.
 */
class ChipPickerJsTest extends TestCase
{
    private string $js;

    protected function setUp(): void
    {
        $this->js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/chip-picker.js');
    }

    public function testExposesSetSelectedOnWindow(): void
    {
        $this->assertStringContainsString('window.ChipPicker', $this->js);
        $this->assertStringContainsString('window.ChipPicker.setSelected = setSelected', $this->js);
    }

    public function testSetSelectedNeverDispatchesChipPickerChange(): void
    {
        // Re-dispatching from setSelected would loop the caller's own
        // chip-picker:change listener straight back into itself.
        $start = strpos($this->js, 'function setSelected(');
        $this->assertNotFalse($start);
        $end = strpos($this->js, 'window.ChipPicker', $start);
        $this->assertNotFalse($end);
        $body = substr($this->js, $start, $end - $start);

        $this->assertStringNotContainsString('dispatchEvent', $body);
        $this->assertStringContainsString('setItemSelected(el, selected)', $body);
        $this->assertStringContainsString('truncate(container)', $body);
    }

    public function testToggleStillDispatchesChipPickerChangeForNormalClicks(): void
    {
        $this->assertStringContainsString("new CustomEvent('chip-picker:change'", $this->js);
    }
}
