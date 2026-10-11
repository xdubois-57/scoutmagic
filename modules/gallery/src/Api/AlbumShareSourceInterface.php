<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * An album as another module may publish it (`social`, docs/chantiers/
 * CHANTIER-partage-social.md) — answered only to someone who manages the
 * album, by the gallery's own rule.
 */
interface AlbumShareSourceInterface
{
    /**
     * Null when the album does not exist, belongs to another module, is
     * being migrated, or is not one this person manages.
     *
     * @param string $role  the requester's role (`Core\Security\Role` value)
     * @param string $email the requester's address, as the gallery matches
     *                      section staff by it
     */
    public function describe(int $albumId, string $role, string $email): ?SharedAlbum;

    /**
     * The album's own page on the site, or null when there is no longer
     * an album there.
     *
     * **It carries no content and asks about no caller**, which is the
     * whole reason it is not {@see describe()}: the one thing that needs
     * it is the public page of a communication sharing this album (issue
     * #706, IT-04), which has to decide whether to offer a « Voir sur le
     * site » button to a visitor with no account. `describe()` would
     * answer null for that visitor whatever the album's state — it
     * answers only someone who manages it — and would read the cover
     * photo's bytes to say so.
     *
     * The album itself is not made public by this, and the page the path
     * names applies its own rule to whoever follows it: a visitor who
     * cannot read the album is asked to sign in, exactly as if they had
     * typed the address.
     */
    public function pagePath(int $albumId): ?string;
}
