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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addExtension(new \Core\View\CompactHtmlExtension());
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));

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
        $this->assertStringContainsString('Espace membres', $html);
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

    /**
     * The offcanvas iterates `menu.pages` — the flat, sorted list — and
     * knows nothing about the named columns MenuBuilder also returns
     * (`menu.groups`, for the desktop panel). Splitting a menu into
     * columns must never reach the mobile navigation: it lists every page
     * of a menu, in one list, in that list's own order.
     */
    public function testMobileOffcanvasListsEveryPageOfAMenuFlat(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true);

        $offcanvasStart = strpos($html, 'id="navOffcanvas"');
        $offcanvasEnd = strpos($html, 'id="desktopNav"');
        $this->assertIsInt($offcanvasStart);
        $this->assertIsInt($offcanvasEnd);
        $offcanvas = substr($html, $offcanvasStart, $offcanvasEnd - $offcanvasStart);

        $notreUnite = substr($offcanvas, (int) strpos($offcanvas, 'id="mob-notre_unite"'));
        $this->assertLessThan(
            strpos($notreUnite, 'Contact'),
            strpos($notreUnite, 'Accueil'),
            'the offcanvas keeps the flat page order'
        );
        // No column title of any kind between the two.
        $this->assertStringNotContainsString('Unité & données', $offcanvas);
    }

    /**
     * Renders the nav from a builder the caller fills itself, for the
     * cases where the shape of the menus — which groups, at which role —
     * is the thing under test.
     */
    private function renderNavFrom(MenuBuilder $builder, string $currentPath = '/'): string
    {
        return $this->twig->render('partials/nav.html.twig', [
            'menus' => $builder->build(),
            'current_path' => $currentPath,
            'is_authenticated' => true,
            'current_user_display_name' => 'test@example.com',
            'current_user_role_label' => 'Admin',
            'site_name' => 'Test Scout',
            'active_menu_id' => '',
            'active_page_url' => '',
        ]);
    }

    // --- desktop mega-menu panel ----------------------------------------

    /**
     * The permanent second row of pills is gone entirely — a floating
     * panel replaces it, so nothing must be left rendering the old row
     * (it would sit under the panel, permanently, at every page load).
     */
    public function testNoSubMenuRowIsRenderedAnyMore(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true, '/setup');

        $this->assertStringNotContainsString('desktop-submenu', $html);
        $this->assertStringNotContainsString('data-submenu-id', $html);
    }

    public function testEachMenuGetsAPanelWiredToItsTab(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true);

        $this->assertMatchesRegularExpression(
            '/data-menu-id="configuration"\s+aria-expanded="false"\s+aria-controls="megamenu-configuration"/',
            $html
        );
        $this->assertStringContainsString('id="megamenu-configuration"', $html);
        // Closed until its tab is clicked (public/assets/js/nav.js).
        $this->assertStringContainsString('class="desktop-megamenu d-none', $html);
    }

    public function testThePanelRendersOneTitledColumnPerGroup(): void
    {
        $builder = new MenuBuilder(Role::SUPERADMIN);
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Desk', '/config/functions', 'superadmin', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-diagram-2', null, 'unite_donnees');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Réglages', '/config/settings', 'superadmin', 20, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-gear-wide-connected', null, 'site');
        $builder->addPage(MenuBuilder::MENU_CONFIGURATION, 'Maintenance', '/config/maintenance', 'superadmin', 30, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-tools', null, 'exploitation');

        $panel = $this->panelOf($this->renderNavFrom($builder), 'configuration');

        $this->assertSame(3, substr_count($panel, 'desktop-megamenu-title'));
        $this->assertMatchesRegularExpression(
            '/Unité &amp; données.*Site.*Exploitation/s',
            $panel,
            'columns follow MENU_GROUPS declaration order'
        );
        $this->assertStringContainsString('bi-tools', $panel);
    }

    /**
     * Role filtering happens before grouping (MenuBuilder::build()), so a
     * column whose every page the visitor may not see is not rendered at
     * all — never a title with nothing under it.
     */
    public function testAGroupLeftEmptyByRoleFilteringRendersNoColumn(): void
    {
        $builder = new MenuBuilder(Role::INTENDANT);
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Staffs', '/chefs/staffs', 'intendant', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-people-fill', null, 'ma_section');
        $builder->addPage(MenuBuilder::MENU_ESPACE_CHEFS, 'Finances', '/finance', 'chief', 20, false, null, MenuBuilder::SORT_GROUP_MODULE, 'bi-cash', null, 'gestion');

        $panel = $this->panelOf($this->renderNavFrom($builder), 'espace_chefs');

        $this->assertStringContainsString('Ma section', $panel);
        $this->assertStringNotContainsString('Gestion', $panel);
        $this->assertSame(1, substr_count($panel, 'desktop-megamenu-title'));
    }

    /**
     * "Notre unité" declares no group at all: one untitled column, no
     * heading invented for it.
     */
    public function testAnUngroupedMenuRendersOneColumnWithNoTitle(): void
    {
        $builder = new MenuBuilder(Role::PUBLIC);
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Accueil', '/', 'public', 10, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-house');
        $builder->addPage(MenuBuilder::MENU_NOTRE_UNITE, 'Contact', '/contact', 'public', 20, false, null, MenuBuilder::SORT_GROUP_CORE, 'bi-envelope');

        $panel = $this->panelOf($this->renderNavFrom($builder), 'notre_unite');

        $this->assertStringNotContainsString('desktop-megamenu-title', $panel);
        $this->assertStringContainsString('Accueil', $panel);
        $this->assertStringContainsString('Contact', $panel);
    }

    /**
     * A per-member entry keeps the avatar and the section subtitle it has
     * in the offcanvas — the panel is a different layout of the same
     * entries, not a plainer copy of them.
     */
    public function testAMemberEntryKeepsItsAvatarAndSubtitleInThePanel(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Baloo', '/members/1', 'identified', 10, true, 'Meute Akela', MenuBuilder::SORT_GROUP_DYNAMIC, null, 7, 'mes_membres');

        $panel = $this->panelOf($this->renderNavFrom($builder, '/members/1'), 'espace_animes');

        $this->assertStringContainsString('Mes membres', $panel);
        $this->assertStringContainsString('Meute Akela', $panel);
        $this->assertStringContainsString('BA', $panel, 'the shared person avatar draws the initials');
        $this->assertStringContainsString('fw-semibold text-primary', $panel);
        $this->assertStringContainsString('aria-current="page"', $panel);
    }

    /**
     * The panel's entries are text rows, never the pills the sub-menu row
     * used: a pill grid is exactly what stopped scaling at nineteen
     * entries.
     */
    public function testPanelEntriesAreTextRowsNotPills(): void
    {
        $panel = $this->panelOf($this->renderNav(Role::SUPERADMIN, true), 'configuration');

        $this->assertStringNotContainsString('btn-outline-secondary', $panel);
        $this->assertStringNotContainsString('btn btn-sm', $panel);
    }

    /**
     * The markup of one menu's panel, from its opening tag to the end of
     * the nav — enough to assert on that menu alone.
     */
    private function panelOf(string $html, string $menuId): string
    {
        $start = strpos($html, 'data-megamenu-id="' . $menuId . '"');
        $this->assertIsInt($start, "no panel for '{$menuId}'");

        $next = strpos($html, 'data-megamenu-id="', (int) $start + 1);

        return $next === false
            ? substr($html, (int) $start)
            : substr($html, (int) $start, $next - (int) $start);
    }

    public function testActivePageHighlighted(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true, '/setup');

        // Every panel starts closed — it floats over the page now, so an
        // open one on load would cover the first thing the visitor reads.
        // The menu the current page belongs to is stated by its tab.
        $this->assertStringContainsString('data-megamenu-id="configuration"', $html);
        $this->assertMatchesRegularExpression(
            '/class="btn btn-sm desktop-menu-btn active"\s+data-menu-id="configuration"/',
            $html
        );
    }

    public function testSubPageOfARegisteredPageKeepsItHighlighted(): void
    {
        // /chefs/staffs/5 isn't itself a registered page (it's a detail
        // sub-route, no menu entry of its own — same shape as finance's
        // /finance/movements under /finance) — the "Staffs" entry must
        // still show as active while browsing it.
        $html = $this->renderNav(Role::INTENDANT, true, '/chefs/staffs/5');
        $this->assertStringContainsString('data-megamenu-id="espace_chefs"', $html);
        $this->assertStringContainsString(
            '<a href="/chefs/staffs" class="d-flex align-items-center gap-2 py-1 text-decoration-none fw-semibold text-primary" aria-current="page">',
            $html
        );
    }

    public function testUnrelatedPageIsNotHighlightedAsASubPage(): void
    {
        // /chefs/staffs2 shares the "/chefs/staffs" text as a raw prefix
        // but is NOT a sub-path of it (no "/" boundary) — must not match.
        $html = $this->renderNav(Role::INTENDANT, true, '/chefs/staffs2');
        $this->assertStringContainsString(
            '<a href="/chefs/staffs" class="d-flex align-items-center gap-2 py-1 text-decoration-none text-body">',
            $html
        );
        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function testActiveStaticEntryTextIsVisibleNotBlueOnBlue(): void
    {
        // Bootstrap's .list-group-item.active paints a solid --bs-primary
        // background WITHOUT !important, while the "active" state below
        // also applies text-primary — a color utility that IS !important
        // (Bootstrap 5) — to the label text. Combining both (as the mobile
        // offcanvas used to) makes the label blue-on-blue: present in the
        // markup, invisible on screen. The fix drops the Bootstrap `active`
        // class from the <li> and relies on the bold blue text alone.
        $html = $this->renderNav(Role::INTENDANT, true, '/chefs/staffs');

        // The entry carries its icon in a fixed 32px box (so its label
        // lines up with the per-member avatars above it) and then the
        // active-state classes on the anchor itself.
        $this->assertMatchesRegularExpression(
            '/<li class="list-group-item list-group-item-action border-0 ps-4 d-flex align-items-center" style="min-height:44px;">\s*(?:\{#.*?#\}\s*)?<a href="\/chefs\/staffs" class="d-flex align-items-center gap-2 text-decoration-none fw-semibold text-primary"/s',
            $html
        );
        $this->assertStringNotContainsString(
            'list-group-item list-group-item-action border-0 ps-4 d-flex align-items-center active',
            $html
        );
    }

    public function testDynamicActiveEntryTextIsVisibleNotBlueOnBlue(): void
    {
        $builder = new MenuBuilder(Role::IDENTIFIED);
        $builder->addPage(MenuBuilder::MENU_ESPACE_ANIMES, 'Baloo', '/members/1', 'identified', 10, true, 'Meute Akela');
        $menus = $builder->build();

        $html = $this->twig->render('partials/nav.html.twig', [
            'menus' => $menus,
            'current_path' => '/members/1',
            'is_authenticated' => true,
            'current_user_display_name' => 'test@example.com',
            'current_user_role_label' => 'Identifié',
            'site_name' => 'Test Scout',
            'active_menu_id' => MenuBuilder::MENU_ESPACE_ANIMES,
            'active_page_url' => '',
        ]);

        $this->assertStringContainsString(
            'class="d-flex align-items-center gap-2 text-decoration-none fw-semibold text-primary"',
            $html
        );
        $this->assertStringNotContainsString(
            'list-group-item list-group-item-action border-0 ps-4 active',
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
        // No aria-label on the link itself — the avatar span it wraps is
        // aria-hidden (see partials/account_avatar.html.twig), so the
        // accessible name comes from this visually-hidden span instead
        // (SonarCloud Web:S7927: an aria-label with no matching visible
        // text is itself a finding).
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertMatchesRegularExpression(
            '/<header[^>]*>.*?<a href="\/account"[^>]*>.*?<span class="visually-hidden">Mon compte<\/span>.*?<\/a>/s',
            $html
        );
    }

    public function testHeaderAvatarAbsentForPublicVisitor(): void
    {
        $html = $this->renderNav(Role::PUBLIC, false);
        [$header] = explode('</header>', $html, 2);
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

    /**
     * The avatar circle itself never links to /notifications anymore in
     * any of the three surfaces — only /account. The sole remaining
     * "/notifications" reference on the page is the notification
     * dropdown's own "Voir toutes les notifications" link, asserted
     * separately below.
     */
    public function testOffcanvasAvatarLinksToAccountNotNotifications(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $offcanvasStart = strpos($html, 'id="navOffcanvas"');
        $offcanvasEnd = strpos($html, '{# Desktop navigation', $offcanvasStart) ?: strlen($html);
        $offcanvas = substr($html, $offcanvasStart, $offcanvasEnd - $offcanvasStart);

        $this->assertStringContainsString('href="/account"', $offcanvas);
        // The avatar is the shared component's circle (Core\View\PersonAvatar)
        // — an <img> when this person has a photo, an initials <span>
        // otherwise, which is what this harness renders.
        $this->assertMatchesRegularExpression(
            '/<a href="\/account"[^>]*>\s*<span class="person-avatar rounded-circle/',
            $offcanvas
        );
    }

    public function testDesktopAvatarLinksToAccountNotNotifications(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $desktopStart = strpos($html, 'id="desktopNav"');
        $this->assertNotFalse($desktopStart);
        $desktop = substr($html, $desktopStart);

        $this->assertMatchesRegularExpression(
            '/<a href="\/account"[^>]*>\s*<span class="person-avatar rounded-circle/',
            $desktop
        );
    }

    public function testNotificationDropdownShowsCountAndLatestAndLink(): void
    {
        // Three unread, three rows: the panel lists up to five (see
        // partials/notification_dropdown.html.twig), because announcing a
        // count over a single row reads as the rest having gone missing.
        $unread = [
            $this->notification(42, 'Nouvelle inscription'),
            $this->notification(41, 'Un nouveau message'),
            $this->notification(40, 'Une réaction'),
        ];

        $builder = new MenuBuilder(Role::ADMIN);
        $menus = $builder->build();

        $html = $this->twig->render('partials/nav.html.twig', [
            'menus' => $menus,
            'current_path' => '/',
            'is_authenticated' => true,
            'current_user_display_name' => 'test@example.com',
            'current_user_role_label' => 'Admin',
            'site_name' => 'Test Scout',
            'active_menu_id' => '',
            'active_page_url' => '',
            'unread_notifications_count' => 3,
            'latest_notifications' => $unread,
        ]);

        $this->assertStringContainsString('3 notifications non lues', $html);
        $this->assertStringContainsString('Nouvelle inscription', $html);
        $this->assertStringContainsString('Un nouveau message', $html);
        $this->assertStringContainsString('Une réaction', $html);
        $this->assertStringContainsString('action="/notifications/42/read"', $html);
        $this->assertStringContainsString('action="/notifications/40/read"', $html);
        $this->assertStringContainsString('href="/notifications"', $html);
        $this->assertStringContainsString('Voir toutes les notifications', $html);
    }

    /**
     * More unread than the panel shows: said plainly, rather than left to
     * be inferred from a count that no longer matches the rows under it.
     */
    public function testNotificationDropdownSaysHowManyMoreArePending(): void
    {
        $html = $this->twig->render('partials/nav.html.twig', [
            'menus' => (new MenuBuilder(Role::ADMIN))->build(),
            'current_path' => '/',
            'is_authenticated' => true,
            'current_user_display_name' => 'test@example.com',
            'current_user_role_label' => 'Admin',
            'site_name' => 'Test Scout',
            'active_menu_id' => '',
            'active_page_url' => '',
            'unread_notifications_count' => 8,
            'latest_notifications' => array_map(
                fn(int $id): \Core\Notification\NotificationRecord => $this->notification($id, 'N' . $id),
                [50, 49, 48, 47, 46]
            ),
        ]);

        $this->assertStringContainsString('et 3 autres', $html);
    }

    private function notification(int $id, string $title): \Core\Notification\NotificationRecord
    {
        return new \Core\Notification\NotificationRecord(
            id: $id,
            userAccountId: 1,
            memberId: null,
            typeId: 'core.test',
            title: $title,
            body: 'Un membre vient de s\'inscrire.',
            url: '/members/1',
            readAt: null,
            createdAt: '2026-08-09 10:00:00'
        );
    }

    /**
     * The "Voir toutes les notifications" link must show up regardless of
     * whether there are any unread notifications — a visitor with none
     * should still be able to reach the full centre from here exactly
     * like one with unread ones can.
     */
    public function testNotificationDropdownShowsEmptyStateWhenNoneUnread(): void
    {
        $html = $this->renderNav(Role::ADMIN, true);
        $this->assertStringContainsString('Aucune notification', $html);
        $this->assertStringNotContainsString('action="/notifications/', $html);
        $this->assertStringContainsString('href="/notifications"', $html);
        $this->assertStringContainsString('Voir toutes les notifications', $html);
    }

    /**
     * The mobile accordion's "where am I" signal: every menu except the
     * active one renders its header with the `collapsed` class (grey,
     * chevron down), only the active menu's header renders expanded.
     *
     * Pinned by a test because the original expression — `{% if not
     * menu.id == active_menu_id %}` — parsed as `(not menu.id) ==
     * active_menu_id` (Twig gives `not` a tighter precedence than `==`),
     * so as soon as ANY menu was active no header got `collapsed` at all:
     * all five sections looked permanently expanded, on every page, and
     * nothing about the bug was visible in a template review.
     */
    public function testOnlyTheActiveMenuHeaderRendersExpanded(): void
    {
        $html = $this->renderNav(Role::SUPERADMIN, true, '/setup');

        preg_match_all(
            '/accordion-button([^"]*)" type="button" data-bs-toggle="collapse" data-bs-target="#mob-([a-z_]+)"/',
            $html,
            $matches,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($matches);

        foreach ($matches as $match) {
            if ($match[2] === 'configuration') {
                $this->assertStringNotContainsString('collapsed', $match[1], 'active menu must render expanded');
            } else {
                $this->assertStringContainsString('collapsed', $match[1], $match[2] . ' must render collapsed');
            }
        }
    }
}
