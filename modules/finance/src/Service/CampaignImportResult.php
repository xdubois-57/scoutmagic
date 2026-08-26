<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * What a validated campaign file contains, before anything is written.
 * Every line resolved to a member and carried a readable amount, or this
 * object does not exist — Service\CampaignImportException is the other
 * outcome, and there is no third.
 */
final class CampaignImportResult
{
    /**
     * @param array<int, array{line: int, member_id: int, amount_cents: int, merge_data: array<string, string>}> $rows
     * @param string[] $mergeColumns headers the reminder can use as variables
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $mergeColumns
    ) {
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function totalCents(): int
    {
        $total = 0;
        foreach ($this->rows as $row) {
            $total += $row['amount_cents'];
        }

        return $total;
    }
}
