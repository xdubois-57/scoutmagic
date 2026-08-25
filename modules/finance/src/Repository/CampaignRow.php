<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

final class CampaignRow
{
    /**
     * @param array<string, string> $mergeData the spreadsheet's other columns, header => value
     */
    public function __construct(
        public readonly int $id,
        public readonly int $campaignId,
        public readonly int $memberId,
        public readonly int $amountCents,
        public readonly int $sourceLine,
        public readonly array $mergeData,
        public readonly ?string $note,
        public readonly ?int $noteAuthorId,
        public readonly ?string $noteUpdatedAt,
        public readonly string $createdAt
    ) {
    }

    public function hasNote(): bool
    {
        return $this->note !== null && trim($this->note) !== '';
    }
}
