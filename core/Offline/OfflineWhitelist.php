<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

/**
 * The single, server-side declaration of which pages the installed app
 * may cache for offline viewing (Lot 3). Consumed on both ends of the
 * same round trip: `public/index.php` exposes it as a Twig global,
 * `base.html.twig` serializes it into the page and hands it to
 * `public/sw.js` via `postMessage` (routing decisions) and to
 * `public/assets/js/offline-nav.js` (greying out links while offline).
 * One list, two consumers — never hand-maintain a second copy in JS.
 *
 * Deliberately excludes anything carrying data this feature must never
 * cache: `/files/{id}`, the member's own page, finance, member documents,
 * mass-mail content (see SECURITY.md's file-access rule and this
 * feature's own "what NOT to do" list).
 */
class OfflineWhitelist
{
    /**
     * @return array<int, array{path: string, label: string}>
     */
    public static function getPaths(): array
    {
        return [
            ['path' => '/', 'label' => 'Accueil'],
            ['path' => '/contact', 'label' => 'Contact'],
            ['path' => '/sections', 'label' => 'Sections'],
            ['path' => '/rgpd', 'label' => 'Protection des données'],
            ['path' => '/calendar', 'label' => 'Calendrier'],
            ['path' => '/notifications', 'label' => 'Notifications'],
            ['path' => '/trombinoscope', 'label' => 'Trombinoscope'],
        ];
    }
}
