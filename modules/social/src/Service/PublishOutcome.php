<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Service;

use Modules\Social\Api\SocialPlatform;

/**
 * How one destination went: published, or not and why — a French sentence
 * the page may show.
 */
final class PublishOutcome
{
    public function __construct(
        public readonly SocialPlatform $platform,
        public readonly bool $published,
        public readonly string $message,
    ) {
    }
}
