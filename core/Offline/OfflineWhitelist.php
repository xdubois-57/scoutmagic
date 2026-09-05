<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Security\Role;

/**
 * The single, server-side declaration of which pages the installed app
 * may cache for offline viewing (Lot 3). An aggregator, same shape as
 * Core\Cookie\CookieConsentService's cookie declarations or
 * Core\Notification\NotificationRegistry's notification types: core
 * entries are hardcoded here (they can never belong to a module), module
 * entries are registered by Core\Module\ModuleManager while loading each
 * enabled module (from that module's own `module.json` `offline`
 * section, validated by Core\Module\ModuleManifest) — never a module path
 * hardcoded in this class, and a disabled module's pages simply never get
 * registered at all.
 *
 * Consumed on both ends of the same round trip: `public/index.php`
 * builds the role-filtered list as a Twig global once every enabled
 * module has registered (see loadEnabledModules() ordering in
 * public/index.php), `base.html.twig` serializes it into the page and
 * hands it to `public/sw.js` via `postMessage` (routing decisions) and to
 * `public/assets/js/offline-nav.js` (greying out unavailable links,
 * intercepting non-GET fetches). One list, several consumers — never
 * hand-maintain a second copy of the DATA in JS (the generic exact/child
 * matching algorithm itself is necessarily reimplemented in JS, since
 * there is no shared runtime between PHP and the browser/service worker
 * — that is not a second copy of the whitelist, only of the matching
 * logic operating on server-supplied data).
 *
 * Deliberately excludes anything carrying data this feature must never
 * cache: `/files/{id}` (the original — variant derivatives are read-only
 * cached separately, see public/sw.js), finance, member documents,
 * mass-mail content, `/inscriptions`, and every admin/configuration page
 * (see SECURITY.md's file-access rule and this feature's own "what NOT to
 * do" list).
 */
class OfflineWhitelist
{
    /**
     * `match: 'child'` means the declared path plus EXACTLY one
     * additional path segment — e.g. `/members/` covers `/members/12`
     * but not `/members/12/emails/5` (two extra segments), with no
     * exclusion list to maintain for the latter.
     *
     * @var array<int, array{path: string, label: string, match: string, role_min: string}>
     */
    private const CORE_ENTRIES = [
        ['path' => '/', 'label' => 'Accueil', 'match' => 'exact', 'role_min' => 'public'],
        ['path' => '/contact', 'label' => 'Contact', 'match' => 'exact', 'role_min' => 'public'],
        ['path' => '/sections', 'label' => 'Sections', 'match' => 'exact', 'role_min' => 'public'],
        ['path' => '/rgpd', 'label' => 'Protection des données', 'match' => 'exact', 'role_min' => 'public'],
        ['path' => '/account', 'label' => 'Mon compte', 'match' => 'exact', 'role_min' => 'identified'],
        ['path' => '/notifications', 'label' => 'Notifications', 'match' => 'exact', 'role_min' => 'identified'],
        ['path' => '/members/', 'label' => 'Membre', 'match' => 'child', 'role_min' => 'identified'],
        // prefetch false: cached when visited, never pre-downloaded at
        // launch — one of the heaviest pages of the site (every staff of
        // a section, badges, photos), and the installed app used to
        // render it on every launch for everyone who might open it.
        [
            'path' => '/chefs/staffs',
            'label' => 'Staffs',
            'match' => 'exact',
            'role_min' => 'intendant',
            'prefetch' => false,
        ],
        // Contextual help (ARCHITECTURE.md §8.64) — product documentation
        // shipped with the release, so keeping it readable offline costs
        // nothing sensitive. role_min public on both: a topic page a role
        // may not see is already a 404 (HelpService), so there is nothing
        // narrower to declare here.
        // `/aide/` also covers `/aide/assistant`, which is deliberate:
        // the page is worth reading offline (it says what the assistant
        // does and does not do), and public/assets/js/help-assistant.js
        // shows « vous êtes hors connexion » there instead of letting a
        // visitor type a question into a field that cannot send it. The
        // ANSWER never comes from the cache — the POST is intercepted by
        // offline-nav.js like every other non-GET.
        ['path' => '/aide', 'label' => 'Aide', 'match' => 'exact', 'role_min' => 'public'],
        ['path' => '/aide/', 'label' => 'Aide', 'match' => 'child', 'role_min' => 'public'],
    ];

    /** @var array<int, array{path: string, label: string, match: string, role_min: string}> */
    private array $moduleEntries = [];

    /**
     * Called by Core\Module\ModuleManager::loadModule() for every enabled
     * module declaring an `offline` section — never called directly by
     * application code.
     *
     * @param array<int, array{path: string, label: string, match: string, role_min: string}> $entries
     */
    public function registerModuleEntries(string $moduleId, array $entries): void
    {
        foreach ($entries as $entry) {
            $this->moduleEntries[] = $entry;
        }
    }

    /**
     * Every declared entry, core + module, unfiltered by role.
     *
     * @return array<int, array{path: string, label: string, match: string, role_min: string}>
     */
    public function getAllEntries(): array
    {
        return array_merge(self::CORE_ENTRIES, $this->moduleEntries);
    }

    /**
     * Whether the installed app pre-downloads this entry at launch
     * (Core\Offline\OfflineManifestService, public/assets/js/
     * offline-prefetch.js). Default yes; an entry declares `prefetch:
     * false` when its page is costly to render and rarely the reason the
     * app was opened — it stays whitelisted, so a visit still caches it.
     *
     * @param array<string, mixed> $entry a whitelist entry, with or without its role_min
     */
    public static function isPrefetched(array $entry): bool
    {
        return $entry['prefetch'] ?? true;
    }

    /**
     * Entries a given role is actually entitled to hold offline — this,
     * not getAllEntries(), is what feeds `#offline-config-data`
     * (base.html.twig) and Core\Offline\OfflineManifestService: a
     * logged-out visitor must never be handed `/previsions` or
     * `/chiefs/stats`, and the client must never try to cache a page it
     * will only ever get a 403 for. `role_min` itself is stripped from
     * the returned shape — it has already done its job by this point.
     *
     * @return array<int, array{path: string, label: string, match: string}>
     */
    public function getEntriesForRole(Role $role): array
    {
        $entries = [];
        foreach ($this->getAllEntries() as $entry) {
            if ($role->hasAccess(Role::fromString($entry['role_min']))) {
                $entries[] = [
                    'path' => $entry['path'],
                    'label' => $entry['label'],
                    'match' => $entry['match'],
                    'prefetch' => self::isPrefetched($entry),
                ];
            }
        }

        return $entries;
    }

    /**
     * Whether $path is covered by ANY declared entry, regardless of role
     * — used server-side by Core\Http\FrontController to decide whether a
     * response is eligible for ETag revalidation. No role filtering is
     * needed here: by the time this runs, the RBAC guard has already
     * decided whether the current request may reach this path at all.
     */
    public function isPathWhitelisted(string $path): bool
    {
        foreach ($this->getAllEntries() as $entry) {
            if (self::matches($entry, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{path: string, match: string} $entry
     */
    private static function matches(array $entry, string $path): bool
    {
        if ($entry['match'] === 'child') {
            if (!str_starts_with($path, $entry['path'])) {
                return false;
            }
            $remainder = trim(substr($path, strlen($entry['path'])), '/');

            return $remainder !== '' && !str_contains($remainder, '/');
        }

        return $path === $entry['path'];
    }
}
