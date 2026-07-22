<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class LoginPageTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $loader = new FilesystemLoader($templateDir);
        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test Scout');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_path', '/login');
        $this->twig->addGlobal('menus', []);
        $this->twig->addGlobal('active_menu_id', '');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('current_user_display_name', '');
        $this->twig->addGlobal('current_user_role_label', '');
        $this->twig->addGlobal('current_user_member_count', 0);
        $this->twig->addGlobal('current_user_email', '');
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

    public function testLoginPageHasThreeActiveTabs(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        // All three tabs should be present and active (no 'disabled' class)
        $this->assertStringContainsString('data-tab="magic-link"', $html);
        $this->assertStringContainsString('data-tab="password"', $html);
        $this->assertStringContainsString('data-tab="passkey"', $html);
        $this->assertStringNotContainsString('nav-link disabled', $html);
    }

    public function testMagicLinkTabIsSelectedByDefault(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        // Magic link tab button should have 'active' class
        $this->assertMatchesRegularExpression('/data-tab="magic-link"[^>]*>Lien magique/', $html);
        // Magic link tab content should NOT have d-none
        $this->assertStringContainsString('id="tab-magic-link" class="">', $html);
        // Password and passkey tab contents should have d-none
        $this->assertStringContainsString('id="tab-password" class="d-none"', $html);
        $this->assertStringContainsString('id="tab-passkey" class="d-none"', $html);
    }

    public function testPasswordTabHasEmailAndPasswordFields(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        $this->assertStringContainsString('id="password-email"', $html);
        $this->assertStringContainsString('id="password-input"', $html);
        $this->assertStringContainsString('id="password-login-btn"', $html);
    }

    public function testPasskeyTabHasFingerprintIconAndButton(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        $this->assertStringContainsString('bi-fingerprint', $html);
        $this->assertStringContainsString('id="passkey-login-btn"', $html);
        $this->assertStringContainsString('Utiliser ma clé', $html);
    }

    public function testCsrfTokenIsPresentInPage(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token_123',
            'default_login_method' => 'magic-link',
        ]);

        $this->assertStringContainsString('test_token_123', $html);
    }

    public function testRgpdConsentCheckboxIsPresentInEachLoginBoxAndLinksToRgpdPage(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        // Module addendum: one checkbox per tab, each placed inside its own
        // login box just above that tab's submit button — not a single
        // shared checkbox outside the boxes.
        $this->assertStringContainsString('id="rgpd-consent-checkbox-magic-link"', $html);
        $this->assertStringContainsString('id="rgpd-consent-checkbox-password"', $html);
        $this->assertStringContainsString('id="rgpd-consent-checkbox-passkey"', $html);
        $this->assertStringContainsString('href="/rgpd"', $html);
    }

    public function testPasswordTabIsPreselectedWhenDefaultLoginMethodIsPassword(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'password',
        ]);

        $this->assertStringContainsString('id="tab-password" class="">', $html);
        $this->assertStringContainsString('id="tab-magic-link" class="d-none"', $html);
        $this->assertStringContainsString('id="tab-passkey" class="d-none"', $html);
    }

    public function testForgotPasswordLinkIsPresentOnPasswordTab(): void
    {
        $html = $this->twig->render('auth/login.html.twig', [
            'csrf_token' => 'test_token',
            'default_login_method' => 'magic-link',
        ]);

        $this->assertStringContainsString('id="forgot-password-link"', $html);
        $this->assertStringContainsString('id="forgot-password-form"', $html);
    }
}
