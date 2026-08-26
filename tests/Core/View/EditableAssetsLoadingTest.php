<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', $configMode);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('editable', fn (): string => '', ['is_safe' => ['html']]));
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn (): string => ''));

        return $twig->render('base.html.twig');
    }

    public function testEditableCssAndJsLoadOutsideConfigurationMode(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/editable.css">', $html);
        $this->assertStringContainsString('<script src="/assets/js/editable.js" defer></script>', $html);
    }

    public function testRichTextEditorModalStaysConfigurationModeOnly(): void
    {
        $html = $this->render(false);

        $this->assertStringNotContainsString('richTextEditorModal', $html);
    }

    public function testEditableCssAndJsStillLoadInConfigurationMode(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/editable.css">', $html);
        $this->assertStringContainsString('<script src="/assets/js/editable.js" defer></script>', $html);
        $this->assertStringContainsString('richTextEditorModal', $html);
    }
}
