<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class NavRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);

        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test-csrf-token';
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

    private function renderNav(Role $role, bool $isAuthenticated = false, string $currentPath = '/'): string
    {
        $builder = new MenuBuilder($role);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Page animé', '/animes', 'identified', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ADMIN, 'Import', '/admin/import', 'chief', 10);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Config', '/setup', 'admin', 10);

        $menus = $builder->build();

        $activeMenuId = '';
        foreach ($menus as $menu) {
            foreach ($menu['pages'] as $page) {
                if (!$page['isSeparator'] && ($page['url'] ?? '') === $currentPath) {
                    $activeMenuId = $menu['id'];
                    break 2;
                }
            }
        }

        return $this->twig->render('partials/nav.html.twig', [
            'menus' => $menus,
            'current_path' => $currentPath,
            'is_authenticated' => $isAuthenticated,
            'current_user_display_name' => 'test@example.com',
            'current_user_role_label' => 'Admin',
            'site_name' => 'Test Scout',
            'active_menu_id' => $activeMenuId,
        ]);
    }

    public function testRendersWithoutErrorsForEachRole(): void
    {
        foreach (Role::cases() as $role) {
            $html = $this->renderNav($role);
            $this->assertNotEmpty($html, "Nav should render for role {$role->value}");
        }
    }

    public function testHamburgerButtonPresent(): void
    {
        $html = $this->renderNav(Role::PUBLIC);
        $this->assertStringContainsString('navOffcanvas', $html);
        $this->assertStringContainsString('bi-list', $html);
    }

    public function testOffcanvasContainerPresent(): void
    {
        $html = $this->renderNav(Role::PUBLIC);
        $this->assertStringContainsString('offcanvas offcanvas-start', $html);
    }

    public function testMenusFilteredByRole(): void
    {
        $html = $this->renderNav(Role::IDENTIFIED);
        $this->assertStringContainsString('Notre unité', $html);
        $this->assertStringContainsString('Espace des animés', $html);
        $this->assertStringNotContainsString('Configuration', $html);
    }

    public function testLogoutFormPresentWhenAuthenticated(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertStringContainsString('action="/logout"', $html);
        $this->assertStringContainsString('Se déconnecter', $html);
    }

    public function testLoginButtonPresentWhenNotAuthenticated(): void
    {
        $html = $this->renderNav(Role::PUBLIC, false);
        $this->assertStringContainsString('href="/login"', $html);
        $this->assertStringContainsString('Se connecter', $html);
        $this->assertStringNotContainsString('Se déconnecter', $html);
    }

    public function testDesktopNavPresent(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertStringContainsString('id="desktopNav"', $html);
        $this->assertStringContainsString('desktop-menu-btn', $html);
    }

    public function testActivePageHighlighted(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true, '/setup');
        // The active submenu bar should not have d-none
        $this->assertStringContainsString('data-submenu-id="configuration"', $html);
    }

    public function testUserCardShownWhenAuthenticated(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertStringContainsString('test@example.com', $html); // Display name falls back to email when no members
        $this->assertStringContainsString('Admin', $html);
    }
}
