<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * What Service\RecommendationService says about one pending request
 * (issue #843, D3/D5): a level — Oui / Peut-être / Non, SlotMath's
 * LEVEL_* — or none at all, and the short French explanation a chief reads
 * beside it.
 *
 * **Staff only.** Nothing of this object may reach a family: not the level,
 * not the explanation, not the rank. The public page shows a slot's level
 * (« Disponible » / « Limitée » / « Complet ») and nothing about any
 * request.
 *
 * Three shapes:
 *
 * - **queued, « Toujours Oui »** (`kind` = KIND_ALWAYS): level Oui;
 * - **queued, ranked** (`kind` = KIND_RANK): the level its occupancy gives,
 *   with `rank` out of `rankedCount`;
 * - **left out of the queue** (`kind` null, `level` null): a possible
 *   duplicate (`duplicateOfRequestId` names the request it duplicates) or a
 *   birth year outside every age bracket.
 */
final class Recommendation
{
    public const KIND_ALWAYS = 'always';
    public const KIND_RANK = 'rank';

    /**
     * @param ?string $level SlotMath::LEVEL_*, or null when there is none
     * @param ?string $kind KIND_*, or null for a request left out of the queue
     * @param ?int $position 1-based place in its slot's queue, « Toujours Oui » included
     * @param ?int $rank 1-based rank among the ranked requests of its slot
     * @param ?int $occupiedBefore the places that would be taken before it,
     *        every request ahead of it counted as accepted
     * @param ?int $capacity its slot's capacity, null = no limit
     */
    public function __construct(
        public readonly int $requestId,
        public readonly ?string $level,
        public readonly string $explanation,
        public readonly ?string $kind = null,
        public readonly ?int $ageBranchId = null,
        public readonly ?int $yearInBranch = null,
        public readonly ?string $slotLabel = null,
        public readonly ?int $position = null,
        public readonly ?int $rank = null,
        public readonly ?int $rankedCount = null,
        public readonly ?int $occupiedBefore = null,
        public readonly ?int $capacity = null,
        public readonly ?int $duplicateOfRequestId = null
    ) {
    }

    public function isQueued(): bool
    {
        return $this->kind !== null;
    }

    /**
     * Whether accepting this request would take its slot past its capacity:
     * every place is already taken before it. Never for a slot without a
     * limit. What the explanation warns about, and what the automatic
     * acceptance (IT-10) must never do except for « Toujours Oui ».
     */
    public function exceedsCapacity(): bool
    {
        return $this->capacity !== null && $this->occupiedBefore !== null
            && $this->occupiedBefore >= $this->capacity;
    }
}
