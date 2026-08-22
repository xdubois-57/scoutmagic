<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\ConfigGeneralController;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * "Édition du site" shrunk to just the configuration-mode toggle —
 * the module registry and badges moved to their own controllers/tests
 * (ConfigModulesControllerTest/ConfigBadgesControllerTest).
 */
class ConfigGeneralControllerTest extends TestCase
{
    private ConfigGeneralController $controller;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief-unite@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));

        $this->controller = new ConfigGeneralController($twig);
    }

    public function testIndexRendersTheConfigModeToggle(): void
    {
        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Édition du site', $body);
        $this->assertStringContainsString('Mode configuration', $body);
        $this->assertStringContainsString('/config-mode/activate', $body);
    }

    public function testIndexShowsDeactivateWhenConfigModeIsAlreadyActive(): void
    {
        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $twig->addFunction(new \Twig\TwigFunction('file_url', fn() => ''));
        $twig->addFunction(new \Twig\TwigFunction('param', fn(string $k) => 'Test'));
        $controller = new ConfigGeneralController($twig);

        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('/config-mode/deactivate', $body);
        $this->assertStringContainsString('Désactiver', $body);
    }

    /**
     * The whole point of the split (ARCHITECTURE §8.11/§7.1): no leftover
     * module registry or badges markup on this page anymore.
     */
    public function testIndexNoLongerRendersModulesOrBadges(): void
    {
        $request = new Request('GET', '/config/general', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringNotContainsString('module-list', $body);
        $this->assertStringNotContainsString('badge-list', $body);
        $this->assertStringNotContainsString('>Modules<', $body);
        $this->assertStringNotContainsString('>Badges<', $body);
    }
}
