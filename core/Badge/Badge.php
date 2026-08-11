<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Badge;

class Badge
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $isDefault,
        public readonly bool $isActive,
        public readonly ?int $referentSectionId = null
    ) {
    }
}
