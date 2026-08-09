<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Photo\UnitLogoService;
use Twig\Environment;

/**
 * Installable-PWA app shell (Lot 1) — manifest, icons, and the offline
 * fallback page. No notifications, no content caching: see public/sw.js
 * for what little the service worker itself does in this lot.
 *
 * icon()/favicon() below outlived "PWA-only" once the unit logo feature
 * widened Core\Photo\UnitLogoService's scope to favicons and the footer
 * logo too (see that class's own docblock) — kept here rather than split
 * into a new controller since they're still the same "installed-app icon,
 * fetched with no session" route family as manifest()/offline().
 */
class PwaController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private UnitLogoService $iconService
    ) {
    }

    /**
     * GET /manifest.webmanifest — every value comes from SettingService,
     * never hardcoded (ARCHITECTURE §1: the codebase is reusable across
     * units). Fetched with no session, so this must stay role_min: public.
     *
     * @param array<string, string> $params
     */
    public function manifest(Request $request, array $params): Response
    {
        $name = (string) ($this->settingService->get('site_name') ?: 'Unité scoute');
        $shortName = (string) ($this->settingService->get('short_name') ?: $name);
        $themeColor = (string) ($this->settingService->get('pwa_theme_color') ?: '#0d6efd');
        $backgroundColor = (string) ($this->settingService->get('pwa_background_color') ?: '#ffffff');
        $version = $this->iconService->currentVersion();

        $manifest = [
            'id' => '/',
            'name' => $name,
            'short_name' => $shortName,
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $backgroundColor,
            'theme_color' => $themeColor,
            'icons' => [
                ['src' => "/pwa/icon-192.png?v={$version}", 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => "/pwa/icon-512.png?v={$version}", 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => "/pwa/icon-512-maskable.png?v={$version}", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        $body = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (new Response($body))
            ->setHeader('Content-Type', 'application/manifest+json')
            ->setHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * GET /pwa/icon-{size}.png — a dedicated public route rather than
     * /files/{id}: a favicon/manifest/home-screen icon is fetched with no
     * session at all, and SECURITY.md §6's single-download-path rule
     * governs session/role-gated files, not static public assets that
     * were never candidates for access control in the first place. $size
     * covers every PNG derivative Core\Photo\UnitLogoService knows how to
     * resolve — the original four PWA sizes (192/512/512-maskable/180)
     * plus, since the unit logo feature widened that service's scope,
     * three favicon sizes (16/32/48) and the footer logo (64). Serves an
     * uploaded override if one exists, otherwise the shipped default —
     * either way, long cache headers plus the version query string
     * (bumped on every upload/deletion/re-derivation) are what actually
     * invalidate a stale cached copy, not the Cache-Control lifetime
     * itself.
     *
     * @param array<string, string> $params
     */
    public function icon(Request $request, array $params): Response
    {
        $size = (string) ($params['size'] ?? '');
        if (!$this->iconService->isValidSize($size)) {
            return new Response('Not Found', 404);
        }

        $content = $this->iconService->resolveIconContent($size);
        if ($content === null) {
            return new Response('Not Found', 404);
        }

        return (new Response($content))
            ->setHeader('Content-Type', 'image/png')
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setHeader('Content-Length', (string) strlen($content));
    }

    /**
     * GET /favicon.ico — the legacy fallback browsers request implicitly
     * at the document root when a page has no (or an unsupported)
     * <link rel="icon">; base.html.twig's <head> always links the PNG
     * favicons too (see manifest()'s sibling icon() route above), which
     * every current browser prefers over this route. Packed by
     * Core\Photo\UnitLogoProcessor::packIco() — a hand-written ICO
     * container around already-PNG-encoded 16/32/48 bytes, no image
     * library beyond GD (see that method's own docblock for why writing
     * ~30 lines here was chosen over serving a bare 32px PNG at this
     * path instead). Cache-Control is deliberately much shorter than
     * icon()'s: this route has no way to be versioned by a query string
     * (a browser's own implicit /favicon.ico request never carries one),
     * so a full year of "immutable" here would mean a stale icon could
     * outlive several re-uploads for any visitor relying on this legacy
     * path alone.
     *
     * @param array<string, string> $params
     */
    public function favicon(Request $request, array $params): Response
    {
        $content = $this->iconService->resolveFaviconIcoContent();
        if ($content === null) {
            return new Response('Not Found', 404);
        }

        return (new Response($content))
            ->setHeader('Content-Type', 'image/x-icon')
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setHeader('Content-Length', (string) strlen($content));
    }

    /**
     * GET /offline — precached app-shell fallback (public/sw.js), shown
     * by the installed app when a navigation fails with no network.
     * Deliberately minimal: no personal data, nothing that could be
     * stale in a way that matters.
     *
     * @param array<string, string> $params
     */
    public function offline(Request $request, array $params): Response
    {
        return $this->render('pwa/offline.html.twig');
    }
}
