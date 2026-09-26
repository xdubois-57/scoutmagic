<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Card;

/**
 * A card just composed: the address Meta will fetch it from, and until
 * when. The token is in the path and nowhere else — it is not stored.
 */
final class IssuedCard
{
    public function __construct(
        public readonly int $id,
        public readonly string $path,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
