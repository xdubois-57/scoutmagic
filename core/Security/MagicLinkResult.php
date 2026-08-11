<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

class MagicLinkResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?int $magicLinkId,
        public readonly ?string $error
    ) {
    }
}
