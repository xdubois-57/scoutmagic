<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class FooterTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);

        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $this->twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));
    }

    public function testFooterContainsRgpdLink(): void
    {
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('href="/rgpd"', $html);
        $this->assertStringContainsString('Protection des données', $html);
    }

    public function testFooterContainsCookiePreferencesLink(): void
    {
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('href="/cookies"', $html);
        $this->assertStringContainsString('Préférences cookies', $html);
    }

    public function testFooterContainsGithubLink(): void
    {
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('href="https://github.com/xdubois-57/scoutmagic"', $html);
    }

    public function testFooterContainsSiteName(): void
    {
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('Test Unit', $html);
    }

    public function testFooterShowsTheUnitLogoWhenAvailable(): void
    {
        $this->twig->addGlobal('unit_logo_available', true);
        $this->twig->addGlobal('pwa_icon_version', 7);

        $html = $this->twig->render('base.html.twig');

        $this->assertMatchesRegularExpression('#<img src="/pwa/icon-64\.png\?v=7" alt=""#', $html);
    }

    /**
     * "Nothing if absent" — same principle as section_photo() (ARCHITECTURE
     * §8.10): no broken <img> when neither an upload nor a shipped default
     * resolves, rather than an empty box or a broken-image icon.
     */
    public function testFooterShowsNoImageWhenNoLogoIsAvailable(): void
    {
        $this->twig->addGlobal('unit_logo_available', false);

        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('icon-64.png', $html);
    }

    public function testFooterRendersWithoutTheUnitLogoGlobalsSetAtAll(): void
    {
        // No unit_logo_available/pwa_icon_version global registered at
        // all — must default safely rather than throwing (mirrors how
        // every other pre-existing test in this class renders base.html.twig
        // with no PWA-related globals either).
        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('icon-64.png', $html);
    }

    public function testHeadLinksTheFaviconPngsWhenAvailable(): void
    {
        $this->twig->addGlobal('unit_logo_available', true);
        $this->twig->addGlobal('pwa_icon_version', 4);

        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="32x32" href="/pwa/icon-32.png?v=4">', $html);
        $this->assertStringContainsString('<link rel="icon" type="image/png" sizes="16x16" href="/pwa/icon-16.png?v=4">', $html);
    }

    public function testHeadOmitsFaviconLinksWhenNoLogoIsAvailable(): void
    {
        $this->twig->addGlobal('unit_logo_available', false);

        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('rel="icon"', $html);
    }
}
