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

        $this->assertSame(3, substr_count($html, 'section-picker') > 0 ? substr_count($html, 'href="/chefs/staffs?section=') : 0);
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
}
