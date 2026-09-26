<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Api;

/**
 * The two places a unit can publish to through this module.
 *
 * Facebook means **a Page**, never a profile and never a group — the
 * groups API was withdrawn by Meta on 22 April 2024. Instagram means a
 * **professional** account (business or creator): a personal account has
 * no publishing API at all.
 */
enum SocialPlatform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';

    /** The name the interface gives it. */
    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Page Facebook',
            self::Instagram => 'Instagram',
        };
    }
}
