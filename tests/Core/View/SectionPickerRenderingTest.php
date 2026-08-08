<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class SectionPickerRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    public function testRendersCorrectNumberOfButtons(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
                ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
                ['id' => 3, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
            ],
            'selected_id' => 1,
            'base_url' => '/chefs/staffs?section=',
        ]);

        $this->assertStringContainsString('section-picker', $html);
        // Each of the 3 sections is reachable from two places sharing the
        // exact same link (chemin de sélection unique, per module-
        // development.md's chip_picker contract): the compact chip AND its
        // full-detail row in the bottom sheet — 2 occurrences per section.
        $this->assertSame(6, substr_count($html, 'href="/chefs/staffs?section='));
        $this->assertSame(3, substr_count($html, 'chip-picker-item chip-picker-chip '));
        $this->assertSame(3, substr_count($html, 'chip-picker-sheet-row'));
    }

    public function testActiveSectionHasBtnPrimaryClass(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
                ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
            ],
            'selected_id' => 1,
            'base_url' => '/test?section=',
        ]);

        // The active button should have btn-primary
        $this->assertStringContainsString('btn-primary', $html);
    }

    public function testInactiveSectionsHaveBtnOutlineSecondary(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
                ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
            ],
            'selected_id' => 1,
            'base_url' => '/test?section=',
        ]);

        // The inactive button should have btn-outline-secondary
        $this->assertStringContainsString('btn-outline-secondary', $html);
    }

    public function testSectionsWithoutNameShowDeskCode(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
            ],
            'selected_id' => 0,
            'base_url' => '/test?section=',
        ]);

        $this->assertStringContainsString('ECL01', $html);
    }

    public function testBranchSubtitleIsDisplayed(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'Ma section', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ],
            'selected_id' => 1,
            'base_url' => '/test?section=',
        ]);

        $this->assertStringContainsString('Baladins', $html);
        $this->assertStringContainsString('Ma section', $html);
    }

    public function testEmptySectionsRendersEmptyPicker(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [],
            'selected_id' => 0,
            'base_url' => '/test?section=',
        ]);

        $this->assertStringContainsString('section-picker', $html);
        $this->assertStringNotContainsString('btn-primary', $html);
    }

    public function testHiddenOrInactiveSectionIsAbsentFromBothChipsAndSheet(): void
    {
        // Core\Member\SectionService::getAllWithBranches() already excludes
        // hidden/inactive sections (ARCHITECTURE.md §8.8) — this partial
        // must never refilter, and must also never leak a section it
        // wasn't given. Only 2 of the unit's 3 real sections are passed
        // here, simulating one filtered out server-side as hidden/inactive.
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'Section visible A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
                ['id' => 2, 'name' => 'Section visible B', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
            ],
            'selected_id' => 1,
            'base_url' => '/chefs/staffs?section=',
        ]);

        $this->assertStringContainsString('Section visible A', $html);
        $this->assertStringContainsString('Section visible B', $html);
        $this->assertStringNotContainsString('Section hidden or inactive', $html);
        $this->assertSame(2, substr_count($html, 'chip-picker-item chip-picker-chip'));
        $this->assertSame(2, substr_count($html, 'chip-picker-sheet-row'));
    }

    public function testUnconfiguredSectionGetsTheBadgeOnlyInTheSheet(): void
    {
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
            ],
            'selected_id' => 0,
            'base_url' => '/test?section=',
        ]);

        [$chipsHtml, $sheetHtml] = explode('chip-picker-sheet', $html, 2);
        $this->assertStringNotContainsString('Non configurée', $chipsHtml);
        $this->assertStringContainsString('Non configurée', $sheetHtml);
    }

    public function testColorIsPassedThroughUnchangedNeverRecomputed(): void
    {
        // Core\Member\SectionService::colorForSection() is the single
        // source of truth (ARCHITECTURE.md §8.8) — this partial must only
        // forward whatever `color` it was given, never derive its own.
        $html = $this->twig->render('partials/section_picker.html.twig', [
            'sections' => [
                ['id' => 1, 'name' => 'A', 'desk_code' => 'A01', 'branch_name' => 'Branche', 'color' => '#123456'],
            ],
            'selected_id' => 0,
            'base_url' => '/test?section=',
        ]);

        $this->assertStringContainsString('#123456', $html);
    }
}
