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
}
