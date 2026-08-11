<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Repository;

/**
 * A rule can combine up to three independent conditions at once — every
 * one of keywordPattern/counterpartyAccountPattern/amountRange that is
 * non-null must match for the rule as a whole to match (AND, not OR).
 * See Service\CategoryRuleEngine.
 */
final class CategoryRule
{
    public function __construct(
        public readonly int $id,
        public readonly int $categoryId,
        public readonly int $priority,
        public readonly ?string $keywordPattern,
        public readonly ?string $counterpartyAccountPattern,
        public readonly ?string $amountRange,
        public readonly bool $isActive,
        public readonly bool $isSystem = false,
        public readonly bool $isDefault = false
    ) {
    }
}
