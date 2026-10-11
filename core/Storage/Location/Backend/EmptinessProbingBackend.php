<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

/**
 * A backend that can say whether its target holds anything without
 * walking it (#832).
 *
 * Asked before a location is attached to a target, while an administrator
 * waits on the form. `list('', null, 1)` would answer too, but a local
 * folder and a WebDAV share build their listing from the whole tree
 * before the limit applies: a large mount or a big Nextcloud would be
 * walked end to end to learn one bit. Object storage honours the limit on
 * the server, and Drive's walk stops at the first file it meets, so they
 * need nothing more.
 */
interface EmptinessProbingBackend extends StorageBackendInterface
{
    /**
     * True as soon as anything is found at the top of the target — a file
     * or a folder, even an empty one: when in doubt, the administrator is
     * asked rather than not.
     */
    public function holdsAnything(): bool;
}
