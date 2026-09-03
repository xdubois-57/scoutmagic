<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Repository;

use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Value\HouseholdTariff;

/**
 * The three rows of `fees_household_tariffs`. Nothing personal here, so no
 * encryption — an amount and a foreign key.
 */
class HouseholdTariffRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** @return array<string, HouseholdTariff> keyed by household category value */
    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT household_category, fee_category_id, amount_cents FROM '
            . 'fees_household_tariffs');
        if ($stmt === false) {
            return [];
        }

        $tariffs = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $category = HouseholdFeeCategory::tryFrom((string) $row['household_category']);
            if ($category === null) {
                continue;
            }
            $tariffs[$category->value] = new HouseholdTariff(
                $category,
                $row['fee_category_id'] === null ? null : (int) $row['fee_category_id'],
                $row['amount_cents'] === null ? null : (int) $row['amount_cents']
            );
        }

        return $tariffs;
    }

    /**
     * Upsert one line. Written as delete-then-insert rather than an
     * `ON DUPLICATE KEY UPDATE`, which SQLite spells differently — the
     * module's tests run on SQLite and its production on MySQL, and one
     * statement that means the same thing on both is worth two round trips
     * on a panel a chief opens twice a year.
     */
    public function save(HouseholdFeeCategory $category, ?int $feeCategoryId, ?int $amountCents): void
    {
        $delete = $this->pdo->prepare('DELETE FROM fees_household_tariffs WHERE household_category = ?');
        $delete->execute([$category->value]);

        $insert = $this->pdo->prepare(
            'INSERT INTO fees_household_tariffs (household_category, fee_category_id, amount_cents, updated_at)
             VALUES (?, ?, ?, ?)'
        );
        $insert->execute([$category->value, $feeCategoryId, $amountCents, date('Y-m-d H:i:s')]);
    }
}
