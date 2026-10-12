<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Security\Role;

/**
 * The single, server-side declaration of what the installed app holds
 * offline. An aggregator, same shape as Core\Cookie\CookieConsentService's
 * cookie declarations or Core\Notification\NotificationRegistry's
 * notification types: core entries are declared here, module entries are
 * registered by Core\Module\ModuleManager while loading each enabled
 * module (from that module's own `module.json` `offline` section,
 * validated by Core\Module\ModuleManifest) — never a module path named in
 * this class, and a disabled module's pages are never registered at all.
 *
 * **A fixed list of exact addresses, and nothing else (#883).** A page is
 * either on the list — downloaded, kept fresh, served offline — or never
 * cached at all, even after a visit. Each entry is either:
 *
 *  - a fixed `path` — `/contact`;
 *  - a `provider` (Core\Offline\OfflinePageProvider) naming the exact
 *    pages for the person asking — the fiches of the members linked to
 *    this account, the first page of the news, the help topics this role
 *    may read. It declares the `prefix` its pages live under; a page
 *    outside it is dropped.
 *
 * There used to be PATTERNS here (« `/members/` plus one segment »),
 * checked by three copies of one algorithm, in PHP, in the service worker
 * and in offline-nav.js. A pattern covers too much: any member's fiche was
 * cached on a visit, and a numeric `/news/{id}` would have greyed nothing
 * offline while caching every article ever opened. Core\Offline\
 * OfflineManifestService now sends the browser the exact list, and the
 * browser holds and greys pages by what is actually in its cache.
 *
 * An entry marked `last` is downloaded after every other page and their
 * images — the help, the least likely reason the app was opened offline.
 *
 * Deliberately excludes anything carrying data this feature must never
 * cache: `/files/{id}` (the original — derivatives are cached separately,
 * see public/sw.js), finance, member documents, mass-mail content,
 * `/inscriptions`, and every admin/configuration page (see SECURITY.md's
 * file-access rule and this feature's own "what NOT to do" list).
 *
 * @phpstan-type OfflineEntry array{
 *     path: ?string,
 *     provider: ?string,
 *     prefix: ?string,
 *     label: string,
 *     role_min: string,
 *     last: bool
 * }
 */
class OfflineWhitelist
{
    /** @var list<OfflineEntry> */
    private const CORE_ENTRIES = [
        [
            'path' => '/',
            'provider' => null,
            'prefix' => null,
            'label' => 'Accueil',
            'role_min' => 'public',
            'last' => false,
        ],
        [
            'path' => '/contact',
            'provider' => null,
            'prefix' => null,
            'label' => 'Contact',
            'role_min' => 'public',
            'last' => false,
        ],
        [
            'path' => '/sections',
            'provider' => null,
            'prefix' => null,
            'label' => 'Sections',
            'role_min' => 'public',
            'last' => false,
        ],
        [
            'path' => '/rgpd',
            'provider' => null,
            'prefix' => null,
            'label' => 'Protection des données',
            'role_min' => 'public',
            'last' => false,
        ],
        [
            'path' => '/account',
            'provider' => null,
            'prefix' => null,
            'label' => 'Mon compte',
            'role_min' => 'identified',
            'last' => false,
        ],
        [
            'path' => '/notifications',
            'provider' => null,
            'prefix' => null,
            'label' => 'Notifications',
            'role_min' => 'identified',
            'last' => false,
        ],
        [
            'path' => null,
            'provider' => LinkedMemberPages::class,
            'prefix' => '/members',
            'label' => 'Fiches des membres liés',
            'role_min' => 'identified',
            'last' => false,
        ],
        // Pre-downloaded since #883, like every other entry: the photos of
        // the Staffs page used to need a visit first.
        [
            'path' => '/chefs/staffs',
            'provider' => null,
            'prefix' => null,
            'label' => 'Staffs',
            'role_min' => 'intendant',
            'last' => false,
        ],
        [
            'path' => null,
            'provider' => HelpPages::class,
            'prefix' => '/aide',
            'label' => 'Aide',
            'role_min' => 'public',
            'last' => true,
        ],
    ];

    /** @var list<OfflineEntry> */
    private array $moduleEntries = [];

    /**
     * Called by Core\Module\ModuleManager::loadModule() for every enabled
     * module declaring an `offline` section — never called directly by
     * application code.
     *
     * @param array<int, OfflineEntry> $entries
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
     * @return list<OfflineEntry>
     */
    public function getAllEntries(): array
    {
        return array_merge(self::CORE_ENTRIES, $this->moduleEntries);
    }

    /**
     * Entries a given role is entitled to hold offline: a logged-out
     * visitor is never handed `/account`, and the client never tries to
     * cache a page it would only ever get a 403 for.
     *
     * @return list<OfflineEntry>
     */
    public function getEntriesForRole(Role $role): array
    {
        return array_values(array_filter(
            $this->getAllEntries(),
            static fn (array $entry): bool => $role->hasAccess(Role::fromString($entry['role_min']))
        ));
    }

    /**
     * Whether a response for $path may carry an ETag — used by
     * Core\Http\FrontController, so the installed app's refresh can be
     * answered « 304 non modifié ». A fixed path, or anything under a
     * provider's prefix, regardless of who is asking: the guard has already
     * decided whether this request may reach the path at all.
     *
     * Never what decides what goes into the cache: that is the exact list
     * of Core\Offline\OfflineManifestService.
     */
    public function isEtagEligible(string $path): bool
    {
        foreach ($this->getAllEntries() as $entry) {
            if ($entry['path'] !== null && $path === $entry['path']) {
                return true;
            }
            if ($entry['prefix'] !== null && self::isUnder($path, $entry['prefix'])) {
                return true;
            }
        }

        return false;
    }

    /** Whether $path is $prefix itself or a page under it. */
    public static function isUnder(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
