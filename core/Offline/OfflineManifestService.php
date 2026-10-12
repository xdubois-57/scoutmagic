<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Security\Role;

/**
 * GET /api/offline/manifest (Core\Http\Controller\OfflineController) — the
 * exact pages the person asking holds offline (#883):
 *
 *     {"pages": ["/", "/contact", "/members/12", "/news", "/news/41"],
 *      "last":  ["/aide", "/aide/installer-application"]}
 *
 * It only assembles what the entries of Core\Offline\OfflineWhitelist give
 * it, filtered by role: a fixed path as it is, a provider's pages as its
 * Core\Offline\OfflinePageProvider names them. It knows no page and no
 * image of its own. The images are read by the service worker in the HTML
 * of the pages it has just stored — what a page shows is what it keeps —
 * so a list of images kept here by hand, page by page, is gone, and with
 * it the trombinoscope that was never in it (#883).
 *
 * `last` is the entries marked so — the help — which the service worker
 * downloads after the other pages and their images.
 */
class OfflineManifestService
{
    public function __construct(
        private OfflineWhitelist $offlineWhitelist,
        private OfflinePageProviders $providers
    ) {
    }

    /**
     * @return array{pages: list<string>, last: list<string>}
     */
    public function buildManifest(Role $role, ?string $email): array
    {
        $pages = [];
        $last = [];

        foreach ($this->offlineWhitelist->getEntriesForRole($role) as $entry) {
            $paths = $entry['path'] !== null ? [$entry['path']] : $this->providedPages($entry, $role, $email);
            if ($entry['last']) {
                array_push($last, ...$paths);
            } else {
                array_push($pages, ...$paths);
            }
        }

        $pages = array_values(array_unique($pages));

        return [
            'pages' => $pages,
            'last' => array_values(array_diff(array_unique($last), $pages)),
        ];
    }

    /**
     * @param array{provider: ?string, prefix: ?string} $entry
     * @return list<string>
     */
    private function providedPages(array $entry, Role $role, ?string $email): array
    {
        $provider = $entry['provider'] !== null ? $this->providers->get($entry['provider']) : null;
        if ($provider === null || $entry['prefix'] === null) {
            return [];
        }

        // A provider never widens its entry: a page outside the declared
        // prefix — or anything that is not a plain path of this site — is
        // dropped rather than handed to the browser to cache.
        return array_values(array_filter(
            $provider->pagesFor($role, $email),
            static fn (string $path): bool => self::isPlainPath($path)
                && OfflineWhitelist::isUnder($path, (string) $entry['prefix'])
        ));
    }

    /**
     * A path of this site and nothing else, checked as it will be cached:
     * one leading '/', no empty, '.' or '..' segment (`/news/../finance`
     * starts with `/news/` and is not under it), no query string and no
     * fragment — the list holds exact addresses, and `/news?page=2` is
     * not `/news`. Rejected rather than normalised: a provider that emits
     * such a path is wrong, and the list does not guess what it meant.
     */
    private static function isPlainPath(string $path): bool
    {
        if (!str_starts_with($path, '/') || strpbrk($path, "?#\\") !== false) {
            return false;
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return $path === '/';
            }
        }

        return true;
    }
}
