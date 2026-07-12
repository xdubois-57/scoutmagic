<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class BannerTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_email', null);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('config_mode', false);
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

    public function testBannerPresentWhenNoConsentGiven(): void
    {
        $this->twig->addGlobal('cookie_consent_given', false);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('id="cookie-banner"', $html);
    }

    public function testBannerAbsentWhenConsentGiven(): void
    {
        $this->twig->addGlobal('cookie_consent_given', true);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('id="cookie-banner"', $html);
    }

    public function testBannerContainsAllThreeButtons(): void
    {
        $this->twig->addGlobal('cookie_consent_given', false);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('id="cookie-accept-all"', $html);
        $this->assertStringContainsString('id="cookie-reject-all"', $html);
        $this->assertStringContainsString('id="cookie-customize"', $html);
        $this->assertStringContainsString('Tout accepter', $html);
        $this->assertStringContainsString('Tout refuser', $html);
        $this->assertStringContainsString('Personnaliser', $html);
    }
}
