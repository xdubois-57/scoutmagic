<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One « avec qui » entry — a name a parent typed, and what the server
 * quietly made of it.
 *
 * The name is a THIRD PARTY: another child, written by somebody else's
 * parent. It is stored encrypted and reaches this object only through
 * `ReenrollmentRepository`.
 *
 * The three match states are all ordinary outcomes. The family typed a
 * name, not an identifier, and the form deliberately offers no
 * autocompletion and no feedback about who was found — so 'ambiguous'
 * (several members carry that name) and 'none' are what a free-text field
 * produces, not errors to report back.
 */
final class FriendWish
{
    public const MATCH_UNIQUE = 'unique';
    public const MATCH_AMBIGUOUS = 'ambiguous';
    public const MATCH_NONE = 'none';

    public function __construct(
        public readonly int $id,
        public readonly int $position,
        public readonly string $rawName,
        public readonly ?int $matchedMemberId,
        public readonly string $matchState
    ) {
    }

    /** Usable by the optimiser: exactly one member, and we know which. */
    public function isUsable(): bool
    {
        return $this->matchState === self::MATCH_UNIQUE && $this->matchedMemberId !== null;
    }
}
