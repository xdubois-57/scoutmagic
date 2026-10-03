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

    /**
     * One line explaining why this provider offers no action right now, or
     * null when it has nothing to explain — either because it is offering
     * an action, or because its absence needs no words.
     *
     * Asked alongside actionsFor() rather than instead of it: a provider
     * that answers both is contributing a button AND a caveat, which is
     * its own business, not this page's.
     */
    public function noteFor(int $albumId): ?AlbumNote;
}
