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
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $this->twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
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
        $this->twig->addGlobal('current_path', '/');
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString('id="cookie-banner"', $html);
    }

    public function testBannerAbsentWhenConsentGiven(): void
    {
        $this->twig->addGlobal('cookie_consent_given', true);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('id="cookie-banner"', $html);
    }

    public function testBannerAbsentOnCookiePreferencesRoute(): void
    {
        // The banner must never be included on /cookies: it is a fixed
        // overlay that was covering the preference toggles and the save
        // button of the very page its "Personnaliser" action leads to.
        $this->twig->addGlobal('cookie_consent_given', false);
        $this->twig->addGlobal('current_path', '/cookies');
        $html = $this->twig->render('base.html.twig');

        $this->assertStringNotContainsString('id="cookie-banner"', $html);
    }

    public function testBannerContainsAllThreeActions(): void
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

    public function testRejectAndAcceptAreIdenticalPrimaryButtons(): void
    {
        // Accept/refuse parity (design.md §1.7): refusing must be exactly
        // as prominent as accepting — same btn-primary classes on both.
        $this->twig->addGlobal('cookie_consent_given', false);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString(
            'class="btn btn-primary btn-sm w-100 w-sm-auto" id="cookie-reject-all"',
            $html
        );
        $this->assertStringContainsString(
            'class="btn btn-primary btn-sm w-100 w-sm-auto" id="cookie-accept-all"',
            $html
        );
        $this->assertStringNotContainsString('btn-outline-secondary btn-sm w-100 w-sm-auto" id="cookie-reject-all"', $html);
    }

    public function testCustomizeIsADiscreetLinkToPreferencesPage(): void
    {
        $this->twig->addGlobal('cookie_consent_given', false);
        $html = $this->twig->render('base.html.twig');

        $this->assertStringContainsString(
            '<a class="btn btn-link btn-sm w-100 w-sm-auto" id="cookie-customize" href="/cookies">',
            $html
        );
    }

    public function testActionsOrderIsRejectThenAcceptThenCustomize(): void
    {
        // design.md §1.7: "Tout refuser", "Tout accepter", then the
        // "Personnaliser" link — DOM order is display order.
        $this->twig->addGlobal('cookie_consent_given', false);
        $html = $this->twig->render('base.html.twig');

        $reject = strpos($html, 'id="cookie-reject-all"');
        $accept = strpos($html, 'id="cookie-accept-all"');
        $customize = strpos($html, 'id="cookie-customize"');
        $this->assertNotFalse($reject);
        $this->assertNotFalse($accept);
        $this->assertNotFalse($customize);
        $this->assertLessThan($accept, $reject);
        $this->assertLessThan($customize, $accept);
    }
}
