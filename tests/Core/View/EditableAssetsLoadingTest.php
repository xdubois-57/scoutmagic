<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * base.html.twig's editable.css / editable.js includes. Both style and
 * script .editable-image (member_photo()'s $editable flag lets a member
 * replace their own photo outside configuration mode — see the member
 * page) as well as .editable-content (rich text, configuration-mode
 * only) — so they must load on every page regardless of config_mode.
 * Reproduces a real regression: both were gated behind
 * "{% if config_mode %}", so outside configuration mode .editable-image
 * had no position:relative/absolute styling at all (the overlay button
 * rendered above the photo instead of on top of it) and editable.js
 * never loaded at all (the edit button did nothing when clicked). Only
 * the rich-text-editor modal itself (config_mode-only content) should
 * stay conditional.
 */
class EditableAssetsLoadingTest extends TestCase
{
    private function render(bool $configMode): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', $configMode);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        return $twig->render('base.html.twig');
    }

    public function testEditableCssAndJsLoadOutsideConfigurationMode(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/editable.css?v=dev">', $html);
        $this->assertStringContainsString('<script src="/assets/js/editable.js?v=dev" defer></script>', $html);
    }

    public function testRichTextEditorModalStaysConfigurationModeOnly(): void
    {
        $html = $this->render(false);

        $this->assertStringNotContainsString('richTextEditorModal', $html);
    }

    public function testEditableCssAndJsStillLoadInConfigurationMode(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/editable.css?v=dev">', $html);
        $this->assertStringContainsString('<script src="/assets/js/editable.js?v=dev" defer></script>', $html);
        $this->assertStringContainsString('richTextEditorModal', $html);
    }
}
