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
 * Nothing consumes it yet: the chantier (docs/chantiers/
 * CHANTIER-partage-social.md) adds the publishing call and its first
 * consumers — the news and the gallery — in later iterations. What exists
 * today is the question every consumer will ask first: is there anywhere
 * to publish?
 *
 * A consumer receives it NULLABLE, null when the module is disabled
 * (ARCHITECTURE.md §7.5).
 */
interface SocialSharingInterface
{
    /**
     * The accounts connected and last found working, in platform order.
     * Never throws: a token that cannot be decrypted, or that Meta
     * refused at the last check, is simply not a destination.
     *
     * @return list<SocialDestination>
     */
    public function connectedDestinations(): array;
}
