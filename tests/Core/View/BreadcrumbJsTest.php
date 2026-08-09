<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/breadcrumb.js and nav.js's showDesktopMenu() export —
 * no JS test runner in this repo (AGENTS.md), so this reads the raw files
 * structurally, same precedent as tests/Core/View/
 * ServiceWorkerPrecacheTest.php. Pins down the mechanism a breadcrumb
 * parent click uses to OPEN a menu (never navigate to a specific page):
 * desktop reuses nav.js's own showDesktopMenu() (never a raw button
 * click(), which would toggle an already-open menu closed instead of
 * ensuring it's open); mobile opens the offcanvas and expands the
 * matching accordion section.
 */
class BreadcrumbJsTest extends TestCase
{
    private string $breadcrumbJs;
    private string $navJs;

    protected function setUp(): void
    {
        $this->breadcrumbJs = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/breadcrumb.js');
        $this->navJs = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/nav.js');
    }

    public function testNavJsExposesShowDesktopMenuOnWindow(): void
    {
        $this->assertStringContainsString('window.ScoutMagicNav', $this->navJs);
        $this->assertStringContainsString('window.ScoutMagicNav.showDesktopMenu = showDesktopMenu', $this->navJs);
    }

    public function testBreadcrumbJsListensOnTheParentButtonClass(): void
    {
        $this->assertStringContainsString("querySelectorAll('.breadcrumb-parent-btn')", $this->breadcrumbJs);
        $this->assertStringContainsString("getAttribute('data-open-menu')", $this->breadcrumbJs);
    }

    public function testBreadcrumbJsDesktopPathReusesNavJsSharedFunctionNeverARawClick(): void
    {
        $this->assertStringContainsString('window.ScoutMagicNav.showDesktopMenu(menuId)', $this->breadcrumbJs);
        $this->assertStringNotContainsString('.click()', $this->breadcrumbJs);
    }

    public function testBreadcrumbJsMobilePathOpensOffcanvasAndExpandsMatchingAccordionSection(): void
    {
        $this->assertStringContainsString("getElementById('navOffcanvas')", $this->breadcrumbJs);
        $this->assertStringContainsString("bootstrap.Offcanvas.getOrCreateInstance", $this->breadcrumbJs);
        $this->assertStringContainsString("getElementById('mob-' + menuId)", $this->breadcrumbJs);
        $this->assertStringContainsString("bootstrap.Collapse.getOrCreateInstance", $this->breadcrumbJs);
    }

    /**
     * Matches Bootstrap 5's own --bs-breakpoint-lg (992px), the same
     * breakpoint partials/nav.html.twig already uses (d-lg-none /
     * d-none d-lg-block) to switch between the mobile and desktop surfaces.
     */
    public function testBreadcrumbJsUsesTheSameBreakpointAsTheNavItself(): void
    {
        $this->assertStringContainsString('min-width: 992px', $this->breadcrumbJs);
    }
}
