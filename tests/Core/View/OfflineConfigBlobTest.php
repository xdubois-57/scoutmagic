<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * base.html.twig's #offline-config-data blob (Lot 3) — the single
 * render-time bridge between server-side globals (Core\Offline\
 * OfflineWhitelist, the offline_cache_staleness_days setting,
 * CookieConsentService::isAllowed('functional'), the account scope) and
 * both public/assets/js/offline-cache.js (which relays this to the
 * service worker via postMessage) and offline-nav.js. Reproduces the
 * plumbing end to end rather than just unit-testing OfflineWhitelist in
 * isolation, since a Twig global wired to the wrong key name would
 * silently ship a page that renders fine but carries no real config.
 */
class OfflineConfigBlobTest extends TestCase
{
    private function render(array $globals): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_email', null);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('csp_nonce', 'nonce123');

        foreach ($globals as $key => $value) {
            $twig->addGlobal($key, $value);
        }

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'test'));

        return $twig->render('base.html.twig');
    }

    private function extractConfig(string $html): array
    {
        $this->assertMatchesRegularExpression(
            '#<script type="application/json" id="offline-config-data">(.*?)</script>#s',
            $html
        );
        preg_match('#<script type="application/json" id="offline-config-data">(.*?)</script>#s', $html, $matches);
        $config = json_decode($matches[1], true);
        $this->assertIsArray($config);

        return $config;
    }

    public function testEmbedsTheStalenessSettingAndWhitelistVerbatim(): void
    {
        $whitelist = [['path' => '/', 'label' => 'Accueil'], ['path' => '/calendar', 'label' => 'Calendrier']];

        $html = $this->render([
            'offline_cache_staleness_days' => 14,
            'offline_functional_consent' => true,
            'offline_whitelist' => $whitelist,
            'offline_account_scope' => '42',
            'app_version' => '1.4.0',
        ]);
        $config = $this->extractConfig($html);

        $this->assertSame(14, $config['stalenessDays']);
        $this->assertTrue($config['consent']);
        $this->assertSame($whitelist, $config['whitelist']);
        $this->assertSame('42', $config['accountScope']);
        $this->assertSame('1.4.0', $config['version']);
    }

    public function testDegradesToSafeDefaultsWhenGlobalsAreMissing(): void
    {
        $config = $this->extractConfig($this->render([]));

        $this->assertSame(7, $config['stalenessDays']);
        $this->assertFalse($config['consent']);
        $this->assertSame([], $config['whitelist']);
        $this->assertSame('guest', $config['accountScope']);
    }

    public function testReflectsWithdrawnConsent(): void
    {
        $config = $this->extractConfig($this->render([
            'offline_functional_consent' => false,
            'offline_account_scope' => 'guest',
        ]));

        $this->assertFalse($config['consent']);
    }
}
