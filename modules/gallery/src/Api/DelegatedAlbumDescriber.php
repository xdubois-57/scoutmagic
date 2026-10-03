<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * How a delegated album is DESCRIBED to an administrator — the read-only
 * twin of Api\DelegatedAlbumAccessChecker, registered the same way and
 * keyed by the same owner_type.
 *
 * A delegated album is deliberately absent from gallery's own browsing and
 * editing pages: what it holds and who may see it belong to the module that
 * owns it. But it is still an album taking real space on a real storage
 * location, and the one thing an administrator legitimately does with it
 * from gallery's side is MOVE it — which was impossible while gallery's
 * configuration page could not so much as list it.
 *
 * Listing it needs a name a human recognises ("Groupe Chefs d'unité", not
 * "discussion_group #7"), and gallery must not learn what a discussion
 * group is to produce one. Hence this: the owning module answers, or — when
 * it is disabled, or the owner row is gone — nobody does and the registry
 * says which of those two happened, in words, with the owner_type and id
 * kept after it as diagnostic detail (issue #749: an identifier is not a
 * name, and an administrator accounting for storage needs to know whether
 * the album is orphaned or merely unclaimed for now). A missing describer
 * never hides the album; an album an administrator cannot see is an album
 * whose storage bill nobody can explain.
 *
 * **Every owner_type a module actually creates albums for wants one of
 * these.** The fallback exists for the disabled module and the deleted
 * owner, not as a substitute for a describer nobody wrote — which is how
 * `camp_camp #10` reached a real administration page.
 * Tests\Architecture\DelegatedAlbumOwnerTypesAreDescribedTest refuses the
 * next one.
 */
interface DelegatedAlbumDescriber
{
    /**
     * Whether this describer is the one responsible for a given owner_type.
     */
    public function supports(string $ownerType): bool;

    /**
     * A short human label for the thing that owns album $ownerId, or null
     * when it no longer exists — an album outliving its owner is exactly
     * the case an administrator most needs to see listed.
     */
    public function describe(int $ownerId): ?string;
}
