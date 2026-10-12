<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Security\Role;

/**
 * Names the EXACT pages an offline entry holds for one person (#883).
 *
 * An entry of the offline list is either a fixed address (`/contact`) or
 * a provider: the fiches of the members linked to this account, the first
 * page of the news, the help topics this role may read. Its answer is a
 * list of paths, each one a page the installed app downloads, keeps fresh
 * and serves offline — and nothing else under the same prefix ever goes
 * into the cache, visited or not.
 *
 * Declared by class name in an `offline` entry (a module's `module.json`,
 * or Core\Offline\OfflineWhitelist for the core ones), and registered once,
 * by instance, in Core\Offline\OfflinePageProviders from the composition
 * root — the same way a module's hooks are wired, without the core ever
 * naming a module.
 */
interface OfflinePageProvider
{
    /**
     * The pages to hold offline for this person. Every path must start
     * with the entry's declared prefix; anything else is dropped, so a
     * provider can never widen the list beyond what its entry declares.
     *
     * @return list<string>
     */
    public function pagesFor(Role $role, ?string $email): array;
}
