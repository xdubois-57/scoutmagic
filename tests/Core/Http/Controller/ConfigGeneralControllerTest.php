<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\ConfigGeneralController;
use Core\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * "Édition du site" shrunk to just the configuration-mode toggle —
 * the module registry and badges moved to their own controllers/tests
 * (ConfigModulesControllerTest/BadgeConfigurationControllerTest).
 */
class ConfigGeneralControllerTest extends TestCase
{
    private ConfigGeneralController $controller;

    protected function setUp(): void
    {
        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chief-unite@test.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

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
        $twig = TestTwig::create([], ['param' => fn(string $k) => 'Test']);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', true);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
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
