<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * partials/section_picker.html.twig — the thin mapping layer from a
 * section row onto a partials/select_bar.html.twig item. It owns no
 * presentation of its own; these tests cover the mapping (which field
 * becomes which item key) and the properties the mapping must preserve,
 * not the bar's own markup, which SelectBarRenderingTest covers.
 *
 * The include signature (`sections`, `selected_id`, `base_url`) is
 * deliberately unchanged from when this mapped onto the chip picker —
 * that is the whole point of the thin-layer pattern, and it is why none
 * of the four call sites needed touching.
 */
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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function render(array $sections, mixed $selectedId, string $baseUrl = '/test?section='): string
    {
        return $this->twig->render('partials/section_picker.html.twig', [
            'sections' => $sections,
            'selected_id' => $selectedId,
            'base_url' => $baseUrl,
        ]);
    }

    public function testRendersOneRealLinkPerSection(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
            ['id' => 3, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
        ], 1, '/chefs/staffs?section=');

        $this->assertStringContainsString('section-picker', $html);
        // One row per section, each a real <a href> — selection needs no
        // JavaScript, which /trombinoscope (OfflineWhitelist) depends on.
        $this->assertSame(3, substr_count($html, 'href="/chefs/staffs?section='));
        $this->assertSame(3, substr_count($html, 'select-bar-item'));
        $this->assertStringContainsString('href="/chefs/staffs?section=1"', $html);
        $this->assertStringContainsString('href="/chefs/staffs?section=2"', $html);
        $this->assertStringContainsString('href="/chefs/staffs?section=3"', $html);
    }

    public function testSelectedSectionIsTheOneMarkedCurrentAndNamedOnTheTrigger(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 2);

        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression(
            '~href="/test\?section=2"[^>]*aria-current="true"~',
            $html
        );
        // The trigger shows the current value, so the selection is
        // readable without opening the panel.
        $this->assertStringContainsString('>Louveteaux A</span>', $html);
    }

    public function testTriggerIsLabelledSection(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 1);

        $this->assertStringContainsString('>Section<', $html);
    }

    public function testNothingSelectedFallsBackToAChoosePrompt(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 0);

        $this->assertStringContainsString('Choisir une section', $html);
        $this->assertStringNotContainsString('aria-current="true"', $html);
    }

    public function testSectionsWithoutNameShowDeskCode(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 0);

        $this->assertStringContainsString('ECL01', $html);
    }

    public function testBranchNameBecomesTheSublabel(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Ma section', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Autre', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 1);

        $this->assertStringContainsString('Baladins', $html);
        $this->assertStringContainsString('Ma section', $html);
    }

    public function testEmptySectionsRendersTheEmptyTextAndNoControl(): void
    {
        $html = $this->render([], 0);

        $this->assertStringContainsString('section-picker', $html);
        $this->assertStringContainsString('Aucune section disponible.', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    public function testHiddenOrInactiveSectionIsAbsent(): void
    {
        // Core\Member\SectionService::getAllWithBranches() already excludes
        // hidden/inactive sections (ARCHITECTURE.md §8.8) — this partial
        // must never refilter, and must also never leak a section it
        // wasn't given. Only 2 of the unit's 3 real sections are passed
        // here, simulating one filtered out server-side as hidden/inactive.
        $html = $this->render([
            ['id' => 1, 'name' => 'Section visible A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Section visible B', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 1, '/chefs/staffs?section=');

        $this->assertStringContainsString('Section visible A', $html);
        $this->assertStringContainsString('Section visible B', $html);
        $this->assertStringNotContainsString('Section hidden or inactive', $html);
        $this->assertSame(2, substr_count($html, 'select-bar-item'));
    }

    public function testUnconfiguredSectionGetsTheNonConfigureeBadge(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 0);

        $this->assertStringContainsString('Non configurée', $html);
    }

    public function testAConfiguredSectionNeverGetsTheBadge(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => 'Baladins A', 'desk_code' => 'BAL01', 'branch_name' => 'Baladins'],
            ['id' => 2, 'name' => 'Louveteaux A', 'desk_code' => 'LOU01', 'branch_name' => 'Louveteaux'],
        ], 1);

        $this->assertStringNotContainsString('Non configurée', $html);
    }

    public function testColorIsPassedThroughUnchangedNeverRecomputed(): void
    {
        // Core\Member\SectionService::colorForSection() is the single
        // source of truth (ARCHITECTURE.md §8.8) — this partial must only
        // forward whatever `color` it was given, never derive its own.
        $html = $this->render([
            ['id' => 1, 'name' => 'A', 'desk_code' => 'A01', 'branch_name' => 'Branche', 'color' => '#123456'],
            ['id' => 2, 'name' => 'B', 'desk_code' => 'B01', 'branch_name' => 'Branche'],
        ], 0);

        $this->assertStringContainsString('#123456', $html);
    }

    /**
     * A unit with exactly one section has nothing to choose, so the bar
     * renders as static text — but the row's own detail still has to
     * survive, or that unit silently loses its branch name and its
     * « Non configurée » badge.
     */
    public function testASingleSectionRendersAsStaticTextKeepingItsDetail(): void
    {
        $html = $this->render([
            ['id' => 1, 'name' => null, 'desk_code' => 'ECL01', 'branch_name' => 'Éclaireurs', 'color' => '#123456'],
        ], 1);

        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('bi-chevron-down', $html);
        $this->assertStringContainsString('ECL01', $html);
        $this->assertStringContainsString('Éclaireurs', $html);
        $this->assertStringContainsString('Non configurée', $html);
        $this->assertStringContainsString('#123456', $html);
    }
}
