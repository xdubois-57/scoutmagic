<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * A module that contributes an action to the page where a chief manages an
 * album (ARCHITECTURE.md §7.6) — the gallery renders what it is given and
 * never learns who gave it.
 *
 * Asked once per page, for the one album shown. The page is already behind
 * the gallery's own rule (the chief manages that album's section); a
 * provider narrows further if it must, and answers an empty list when it
 * has nothing to offer.
 */
interface AlbumActionProviderInterface
{
    /**
     * @return list<AlbumAction>
     */
    public function actionsFor(int $albumId): array;
}
