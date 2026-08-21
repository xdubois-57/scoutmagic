<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The two navigation conventions this project commits to (design.md §7.1),
 * enforced mechanically so a later change cannot quietly reintroduce what
 * was just removed.
 *
 * 1. **No page carries its own "Retour" affordance.** The breadcrumb bar is
 *    the site's single back mechanism — it is rendered once by
 *    base.html.twig, visible on every mobile viewport, and a per-page
 *    button both duplicates it and disagrees with it (13 such buttons
 *    existed in 6 different visual treatments).
 * 2. **Every page route declares a breadcrumb, and its `parents` name real
 *    menus.** A page with no declaration renders the bar as a lone home
 *    icon, which reads as a broken component; a `parents` entry that
 *    matches no menu silently degrades from a button to inert grey text.
 *
 * Structural test reading the raw templates and manifests, same precedent
 * as tests/Core/Http/ConfigBreadcrumbRoutesTest.php.
 */
class UxConventionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /**
     * The only two places a "Retour" control is legitimate, each because the
     * breadcrumb genuinely cannot serve there.
     *
     * @var array<string, string>
     */
    private const BACK_CONTROL_EXCEPTIONS = [
        // Deliberately does not extend base.html.twig (it is precached by
        // the service worker and must bake in nothing dynamic), so it has
        // no breadcrumb at all. In an installed PWA there is no browser
        // back button either, making history.back() the only way back to
        // the cached page the visitor was reading.
        'core/View/templates/pwa/offline.html.twig' => 'history.back() on a page outside the app shell',
        // "Retour au brouillon" switches a dialog between its test and
        // draft states. It navigates nowhere.
        'modules/mass_mail/views/_compose_dialog.html.twig' => 'state toggle inside a dialog',
    ];

    /**
     * Non-page routes: AJAX fragments, JSON endpoints, downloads and media
     * streams. They render no breadcrumb bar, so they declare no breadcrumb.
     */
    private const NON_PAGE_PATTERN = '#(/api/|/export|/feed|/download|/qr$|/poll$|\.ics$|/media/|/thumbnail|-status$|/options$|/search$|/reactions$|/replies$|/seen-by$|/member-search$|/mention-search$|/transitions$|/installations/|/poster$|/document/|/unsubscribe/|/\{id\}/movements$|/\{id\}/attachments$)#';

    public function testNoTemplateCarriesItsOwnBackButton(): void
    {
        $offenders = [];

        foreach ($this->templates() as $file) {
            $relative = substr($file, strlen($this->root) + 1);
            if (isset(self::BACK_CONTROL_EXCEPTIONS[$relative])) {
                continue;
            }

            $contents = (string) file_get_contents($file);
            // A visible control labelled as a way back: "Retour", "« Retour",
            // "← Retour", with or without a leading icon.
            if (preg_match('/>\s*(?:<i[^>]*><\/i>\s*)?(?:&laquo;|«|←)?\s*Retour\b/u', $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These templates carry their own "Retour" control. The breadcrumb bar is '
            . 'the site\'s single back affordance (design.md §7.1): declare the missing '
            . 'step (route `breadcrumb`, or `breadcrumb_trail` from the controller when '
            . 'the ancestor is a page rather than a menu) instead of adding a button.'
        );
    }

    public function testEveryBreadcrumbParentNamesARealMenu(): void
    {
        $known = [];
        foreach (['notre_unite', 'espace_animes', 'espace_chefs', 'espace_admin', 'configuration'] as $id) {
            $known[] = MenuBuilder::labelFor($id);
        }

        $offenders = [];
        foreach ($this->manifests() as $moduleId => $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                foreach ($route['breadcrumb']['parents'] ?? [] as $parent) {
                    if (!in_array($parent, $known, true)) {
                        $offenders[] = "{$moduleId} {$route['path']} → « {$parent} »";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A `parents` entry must name a real menu (Core\\View\\MenuBuilder::MENUS); '
            . 'anything else renders as inert grey text instead of a button. For an '
            . 'ancestor that is a PAGE rather than a menu, use `breadcrumb_trail` from '
            . 'the controller, which renders a real link (design.md §7.1).'
        );
    }

    public function testEveryModulePageRouteDeclaresABreadcrumb(): void
    {
        $offenders = [];
        foreach ($this->manifests() as $moduleId => $manifest) {
            foreach ($manifest['routes'] ?? [] as $route) {
                if (($route['method'] ?? 'GET') !== 'GET') {
                    continue;
                }
                if (preg_match(self::NON_PAGE_PATTERN, $route['path'])) {
                    continue;
                }
                if (isset($route['breadcrumb'])) {
                    continue;
                }
                $offenders[] = "{$moduleId} {$route['path']}";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These pages declare no breadcrumb: the bar collapses to a lone home icon, '
            . 'which reads as a broken component. Add `breadcrumb` to the manifest route, '
            . 'or extend NON_PAGE_PATTERN if the route renders no page at all.'
        );
    }

    /** @return string[] */
    private function templates(): array
    {
        $out = [];
        foreach ([$this->root . '/core/View/templates', $this->root . '/modules'] as $base) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
            foreach ($it as $f) {
                $path = $f->getPathname();
                if (!str_ends_with($path, '.html.twig')) {
                    continue;
                }
                // Email templates are read in a mail client: no app shell,
                // no breadcrumb, and no navigation conventions apply.
                if (str_contains($path, '/email/')) {
                    continue;
                }
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function manifests(): array
    {
        $out = [];
        foreach (glob($this->root . '/modules/*/module.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $out[$decoded['id'] ?? basename(dirname($file))] = $decoded;
            }
        }

        return $out;
    }
}
