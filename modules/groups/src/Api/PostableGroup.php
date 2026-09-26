<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

/**
 * A discussion group the caller may post in, as another module shows it:
 * its name and how many people read it — what tells « Staff Lutins » from
 * « Staff Pionniers » without guessing.
 */
final class PostableGroup
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $memberCount,
    ) {
    }
}
