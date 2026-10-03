<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Api;

/**
 * What another module may ask of the social connector.
 *
 * Its consumers are the gallery's album form and the news editor, which
 * offer « Partager » — and must know, before drawing a button, whether
 * this person has anywhere to publish at all.
 *
 * A consumer receives it NULLABLE, null when the module is disabled
 * (ARCHITECTURE.md §7.5).
 */
interface SocialSharingInterface
{
    /**
     * The unit's Meta accounts connected and last found working, in
     * platform order. Never throws: a token that cannot be decrypted, or
     * that Meta refused at the last check, is simply not a destination.
     *
     * @return list<SocialDestination>
     */
    public function connectedDestinations(): array;

    /**
     * Whether THIS person has anywhere to publish right now: one of the
     * accounts above, or — the reason this question is broader than that
     * list — a discussion group they may post in
     * (docs/chantiers/CHANTIER-medias-sociaux.md, IT-01).
     *
     * Asked of the viewer rather than of the site, because the answer
     * differs between two chiefs: discussion groups are per-person, and a
     * unit with no Meta account at all still shares to its groups.
     */
    public function canPublish(?string $email, string $role, ?int $userAccountId): bool;
}
