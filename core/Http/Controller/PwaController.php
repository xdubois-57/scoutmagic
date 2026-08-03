<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Photo\PwaIconService;
use Twig\Environment;

/**
 * Installable-PWA app shell (Lot 1) — manifest, icons, and the offline
 * fallback page. No notifications, no content caching: see public/sw.js
 * for what little the service worker itself does in this lot.
 */
class PwaController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private PwaIconService $iconService
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
     * /files/{id}: an installed app's manifest/home-screen icon is
     * fetched with no session at all, and SECURITY.md §6's single-
     * download-path rule governs session/role-gated files, not static
     * public assets that were never candidates for access control in the
     * first place. Serves an uploaded override if one exists
     * (Core\Photo\PwaIconService), otherwise the shipped default —
     * either way, long cache headers plus the version query string
     * (bumped on every new upload) are what actually invalidate a stale
     * cached copy, not the Cache-Control lifetime itself.
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
