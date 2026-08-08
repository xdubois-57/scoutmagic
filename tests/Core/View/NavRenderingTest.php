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

        // Same longest-prefix-or-exact-match logic as public/index.php —
        // a page's own sub-routes (registered with an empty label so they
        // never get their own menu button) aren't present in $menu's pages
        // at all, so an exact-match-only comparison would lose the
        // highlight entirely once navigating into one of them.
        $activeMenuId = '';
        $activePageUrl = '';
        $bestMatchLength = -1;
        foreach ($menus as $menu) {
            foreach ($menu['pages'] as $page) {
                if ($page['isSeparator']) {
                    continue;
                }
                $pageUrl = $page['url'] ?? '';
                if ($pageUrl === '') {
                    continue;
                }
                $isExact = $pageUrl === $currentPath;
                $isPrefix = !$page['isDynamic'] && $pageUrl !== '/' && str_starts_with($currentPath, $pageUrl . '/');
                if (($isExact || $isPrefix) && strlen($pageUrl) > $bestMatchLength) {
                    $activeMenuId = $menu['id'];
                    $activePageUrl = $pageUrl;
                    $bestMatchLength = strlen($pageUrl);
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
            'active_page_url' => $activePageUrl,
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

    public function testSubPageOfARegisteredPageKeepsItHighlighted(): void
    {
        // /chefs/staffs/5 isn't itself a registered page (it's a detail
        // sub-route, no menu entry of its own — same shape as finance's
        // /finance/movements under /finance) — the "Staffs" button/list
        // item must still show as active while browsing it.
        $html = $this->renderNav(Role::INTENDANT, true, '/chefs/staffs/5');
        $this->assertStringContainsString('data-submenu-id="espace_chefs"', $html);
        $this->assertMatchesRegularExpression(
            '/href="\/chefs\/staffs"\s+class="btn btn-sm btn-primary"/',
            $html
        );
    }

    public function testUnrelatedPageIsNotHighlightedAsASubPage(): void
    {
        // /chefs/staffs2 shares the "/chefs/staffs" text as a raw prefix
        // but is NOT a sub-path of it (no "/" boundary) — must not match.
        $html = $this->renderNav(Role::INTENDANT, true, '/chefs/staffs2');
        $this->assertDoesNotMatchRegularExpression(
            '/href="\/chefs\/staffs"\s+class="btn btn-sm btn-primary"/',
            $html
        );
    }

    public function testUserCardShownWhenAuthenticated(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertStringContainsString('test@example.com', $html); // Display name falls back to email when no members
        $this->assertStringContainsString('Admin', $html);
    }

    public function testHeaderAvatarShownForIdentifiedSession(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertMatchesRegularExpression(
            '/<header[^>]*>.*?<a href="\/account"[^>]*aria-label="Mon compte"[^>]*>/s',
            $html
        );
    }

    public function testHeaderAvatarAbsentForPublicVisitor(): void
    {
        $html = $this->renderNav(Role::PUBLIC, false);
        [$header] = explode('</header>', $html, 2);
        $this->assertStringNotContainsString('aria-label="Mon compte"', $header);
        $this->assertStringNotContainsString('/account', $header);
    }

    public function testHeaderAvatarLinksToAccountNotOffcanvas(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        [$header] = explode('</header>', $html, 2);
        // Only the hamburger button toggles the offcanvas — the avatar link
        // must not also carry that trigger.
        $this->assertStringContainsString('data-bs-toggle="offcanvas"', $header);
        $accountLinkPos = strpos($header, 'href="/account"');
        $this->assertNotFalse($accountLinkPos);
        $accountAnchorTag = substr($header, $accountLinkPos - 80, 160);
        $this->assertStringNotContainsString('data-bs-toggle', $accountAnchorTag);
    }

    public function testNotificationBadgeRenderedInBothHeaderAndOffcanvasWhenAuthenticated(): void
    {
        // 3 badges: the new mobile header avatar, the mobile offcanvas
        // user card (both via the shared partials/account_avatar.html.twig
        // include), plus desktop nav's own separate, untouched badge
        // (out of scope for this change, see nav.html.twig's #desktopNav).
        // notification-badge.js updates all of them via querySelectorAll.
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertSame(3, substr_count($html, 'notification-badge'));
    }

    public function testNotificationBadgeAbsentWhenNotAuthenticated(): void
    {
        $html = $this->renderNav(Role::PUBLIC, false);
        $this->assertSame(0, substr_count($html, 'notification-badge'));
    }
}
