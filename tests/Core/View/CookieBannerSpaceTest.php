<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The cookie banner and the space kept for it are one state (#742): the
 * `cookie-banner-visible` class on <body> comes with the banner, under the
 * same condition, and never without it — /cookies included. The CSS reads
 * that class, not `body:has(> #cookie-banner)`, which left a visitor with
 * an empty white strip the browser never re-evaluated away.
 */
class CookieBannerSpaceTest extends TestCase
{
    private function render(bool $consentGiven, string $path): string
    {
        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', $consentGiven);
        $twig->addGlobal('current_path', $path);
        $twig->addGlobal('menus', null);

        return $twig->render('base.html.twig');
    }

    public function testTheBannerAndItsSpaceComeTogether(): void
    {
        $html = $this->render(false, '/');

        $this->assertStringContainsString('<body class="cookie-banner-visible">', $html);
        $this->assertStringContainsString('id="cookie-banner"', $html);
    }

    public function testNoSpaceIsKeptWithoutTheBanner(): void
    {
        $html = $this->render(true, '/');

        $this->assertStringNotContainsString('cookie-banner-visible', $html);
        $this->assertStringNotContainsString('id="cookie-banner"', $html);
    }

    public function testTheCookiesPageGetsNeitherTheBannerNorItsSpace(): void
    {
        $html = $this->render(false, '/cookies');

        $this->assertStringNotContainsString('cookie-banner-visible', $html);
        $this->assertStringNotContainsString('id="cookie-banner"', $html);
    }

    public function testTheStylesheetNoLongerLeansOnHas(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');

        $this->assertStringNotContainsString('body:has(> #cookie-banner) {', $css);
        $this->assertSame(3, substr_count($css, 'body.cookie-banner-visible {'));
    }
}
