<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Banner\Repository;

class Banner
{
    /** @var string[] */
    public const ROLE_MINS = ['public', 'identified', 'chief'];

    public function __construct(
        public readonly int $id,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        public readonly string $roleMin
    ) {
    }
}
