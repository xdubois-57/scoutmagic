<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File;

/**
 * The registry's rendition-aware question (issue #1108): may a REDUCED
 * rendition of this owner's file — a `thumb` or `md` derivative from
 * Core\Photo\ImageVariantService, never the original — be served on
 * `role_min` alone?
 *
 * Optional, like {@see FileIndexingPolicyInterface}. A checker that does
 * not implement it is asked {@see FileOwnershipCheckerInterface::isAllowed()}
 * for every rendition, which is the conservative answer: the owner rule
 * applies to the thumbnail too. One that does implement it can say that a
 * face shown small on a group's member list is not the same disclosure as
 * the full-resolution photograph, and keep its rule for the original.
 */
interface ReducedRenditionPolicyInterface
{
    /**
     * Whether a reduced rendition of the file owned by $ownerId is served
     * to whoever already passes the file's `role_min`.
     */
    public function allowsReducedRendition(int $ownerId): bool;
}
