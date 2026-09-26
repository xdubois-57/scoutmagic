<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Api;

/**
 * One account the site can publish to right now: its platform and the
 * name the unit knows it by — the Page's name, or the Instagram handle
 * without its « @ ».
 */
final class SocialDestination
{
    public function __construct(
        public readonly SocialPlatform $platform,
        public readonly string $accountName,
    ) {
    }
}
