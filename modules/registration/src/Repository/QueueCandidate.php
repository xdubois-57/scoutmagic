<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * One request as the recommendation queue sees it (issue #843, D5) — the
 * few fields Service\RecommendationService needs, and nothing else of the
 * fiche: no name, no address, no contact. Assembled only by
 * RegistrationRequestRepository::findQueueCandidatesForYear(), which
 * decrypts the birth date of a PENDING request and nothing more — only a
 * pending request is placed in a slot; an accepted or encoded one is read
 * for its child key and its date of filing, and carries a null birth date.
 *
 * `nameDobBlindIndex` is the comparison-only key of the child (normalised
 * name + birth date, schema.sql): two requests carrying the same one are
 * the same child as far as the site can tell. It is never displayed.
 */
final class QueueCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $status,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly string $nameDobBlindIndex,
        public readonly ?string $birthDate
    ) {
    }

    /**
     * The order in which requests were filed: the date of filing, then the
     * id, so two requests filed in the same second still have one order —
     * the same one on every computation.
     */
    public static function compareFiling(self $a, self $b): int
    {
        return [$a->receivedAt->getTimestamp(), $a->id] <=> [$b->receivedAt->getTimestamp(), $b->id];
    }
}
