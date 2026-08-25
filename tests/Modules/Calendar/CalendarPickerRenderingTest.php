<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Thin mapping layer over partials/select_bar.html.twig (mode: single) —
 * same precedent as Tests\Core\View\SectionPickerRenderingTest. A
 * calendar list is open-ended and comes from the database, which is the
 * select bar's case rather than the nav rail's.
 *
 * public.html.twig is `role_min: public`, so nothing here may assume a
 * session.
 */
class CalendarPickerRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $coreTemplates = dirname(__DIR__, 3) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 3) . '/modules/calendar/views';
        $loader = new FilesystemLoader($coreTemplates);
        $loader->addPath($moduleViews, 'calendar');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
    }

    public function testRendersOneRealLinkPerCalendar(): void
    {
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [
                ['id' => 0, 'label' => 'Mes évènements', 'color' => null],
                ['id' => 1, 'label' => 'Louveteaux', 'color' => '#f5a623'],
            ],
            'selected_id' => 1,
            'base_url' => '/chefs/calendar?calendar=',
        ]);

        $this->assertStringContainsString('calendar-picker', $html);
        // One row per calendar, each a real <a href> — /calendar is in
        // Core\Offline\OfflineWhitelist, so selection must not need JS.
        $this->assertSame(1, substr_count($html, 'href="/chefs/calendar?calendar=1"'));
        $this->assertSame(1, substr_count($html, 'href="/chefs/calendar?calendar=0"'));
        $this->assertStringContainsString('Mes évènements', $html);
        $this->assertStringContainsString('Louveteaux', $html);
    }

    public function testSelectedOptionIsMarkedCurrentAndNamedOnTheTrigger(): void
    {
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [
                ['id' => 0, 'label' => 'Mes évènements', 'color' => null],
                ['id' => 1, 'label' => 'Louveteaux', 'color' => null],
            ],
            'selected_id' => 0,
            'base_url' => '/calendar?calendar=',
        ]);

        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
        $this->assertMatchesRegularExpression(
            '~href="/calendar\?calendar=0"[^>]*aria-current="true"~',
            $html
        );
        $this->assertStringContainsString('>Mes évènements</span>', $html);
    }

    public function testTriggerIsLabelledCalendrier(): void
    {
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [
                ['id' => 0, 'label' => 'Mes évènements', 'color' => null],
                ['id' => 1, 'label' => 'Louveteaux', 'color' => null],
            ],
            'selected_id' => 0,
            'base_url' => '/calendar?calendar=',
        ]);

        $this->assertStringContainsString('>Calendrier<', $html);
    }

    public function testExtraQueryPreservesMonthNavigationOnEveryLink(): void
    {
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [
                ['id' => 0, 'label' => 'Mes évènements', 'color' => null],
                ['id' => 1, 'label' => 'Louveteaux', 'color' => null],
            ],
            'selected_id' => 0,
            'base_url' => '/calendar?calendar=',
            'extra_query' => '&month=2026-08',
        ]);

        // The month must survive switching calendar, on every row.
        $this->assertSame(1, substr_count($html, 'href="/calendar?calendar=0&amp;month=2026-08"'));
        $this->assertSame(1, substr_count($html, 'href="/calendar?calendar=1&amp;month=2026-08"'));
    }

    public function testColorDerivedFromSectionIsForwardedUnchanged(): void
    {
        // Modules\Calendar\Service\CalendarPickerService::buildOptions()
        // already resolves each section calendar's color via
        // Core\Member\SectionService::colorForSection() — this partial
        // must only forward it, never derive its own color table.
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [
                ['id' => 0, 'label' => 'Mes évènements', 'color' => null],
                ['id' => 1, 'label' => 'Louveteaux', 'color' => '#f5a623'],
            ],
            'selected_id' => 1,
            'base_url' => '/calendar?calendar=',
        ]);

        $this->assertStringContainsString('#f5a623', $html);
    }

    public function testEmptyOptionsRendersEmptyState(): void
    {
        $html = $this->twig->render('@calendar/partials/calendar_picker.html.twig', [
            'options' => [],
            'selected_id' => 0,
            'base_url' => '/calendar?calendar=',
        ]);

        $this->assertStringContainsString('calendar-picker', $html);
        $this->assertStringContainsString('Aucun calendrier disponible.', $html);
    }
}
