<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * A member_year's departure-marking state — Core\Member\DepartureService.
 * $comment is already decrypted (DepartureRepository is the only layer
 * that touches EncryptionService for it).
 */
final class DepartureStatus
{
    public function __construct(
        public readonly bool $leaving,
        public readonly ?\DateTimeImmutable $markedAt,
        public readonly ?string $comment
    ) {
    }
}
