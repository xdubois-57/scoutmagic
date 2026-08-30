<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Modules\Attestations\Value\MatchState;

/**
 * One certificate, already cut out of the deposited file and waiting for a
 * human decision. A read model: `public readonly` properties, no logic
 * beyond reading its own fields.
 *
 * `$readName` is the name as PRINTED, which is not always the name the site
 * holds — showing it beside the member it was matched to is the whole point
 * of the verification screen.
 */
final class BatchLine
{
    /**
     * @param list<int> $candidateMemberIds every member this line could
     *                                      belong to; only ever non-empty
     *                                      while the state is ambiguous
     */
    public function __construct(
        public readonly int $id,
        public readonly int $batchId,
        public readonly int $position,
        public readonly int $firstPage,
        public readonly int $lastPage,
        public readonly ?string $readName,
        public readonly ?int $memberId,
        public readonly MatchState $state,
        public readonly int $fileId,
        public readonly bool $isSelected,
        public readonly array $candidateMemberIds = []
    ) {
    }

    /**
     * A line with no member has no destination: there is no page to put the
     * document on and nobody to send it to. It is resolved or it is left
     * aside, never distributed.
     */
    public function isDistributable(): bool
    {
        return $this->memberId !== null;
    }

    /** True while the line still needs somebody to decide something. */
    public function needsAttention(): bool
    {
        return $this->memberId === null;
    }

    /** « 1–2 », or « 3 » for a one-page certificate. */
    public function pageRangeLabel(): string
    {
        return $this->firstPage === $this->lastPage
            ? (string) $this->firstPage
            : $this->firstPage . '–' . $this->lastPage;
    }
}
