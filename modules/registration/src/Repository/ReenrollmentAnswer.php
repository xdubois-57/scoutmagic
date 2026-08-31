<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One family's answer about next scout year, decrypted.
 *
 * Hydrated only by `ReenrollmentRepository` — the family comment and every
 * friend's name reach this object already in clear, and reach it nowhere
 * else (SECURITY.md §5).
 */
final class ReenrollmentAnswer
{
    public const DECISION_REENROLLED = 'reenrolled';
    public const DECISION_LEAVING = 'leaving';

    /**
     * @param array<int, FriendWish> $friendWishes in the order the family
     *        typed them, the cap NOT applied — that is the reader's call,
     *        so lowering it never destroys what was entered
     */
    public function __construct(
        public readonly int $id,
        public readonly int $memberId,
        public readonly int $scoutYearId,
        public readonly string $decision,
        public readonly ?int $preferredSectionId,
        public readonly ?string $familyComment,
        public readonly \DateTimeImmutable $answeredAt,
        public readonly ?int $answeredByUserAccountId,
        public readonly array $friendWishes = []
    ) {
    }

    public function isReenrolled(): bool
    {
        return $this->decision === self::DECISION_REENROLLED;
    }

    /**
     * The wishes that count today, given the current cap: the first N in
     * the order the family typed them.
     *
     * The cap is applied on READ, never on delete. A unit that lowers it
     * from three to two stops using the third; it does not lose it, and
     * raising the setting back brings it into play again. Destroying
     * something a family entered because a chief moved a number would be
     * a strange thing for a site to do.
     *
     * @return array<int, FriendWish>
     */
    public function friendWishesWithin(int $limit): array
    {
        return $limit > 0 ? array_slice($this->friendWishes, 0, $limit) : [];
    }
}
