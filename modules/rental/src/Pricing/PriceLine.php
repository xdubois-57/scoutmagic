<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Pricing;

/**
 * One line of a quote: a label, a quantity, a unit price, an amount, and the
 * rule that produced it (§6.10).
 *
 * `$rule` is what makes a quote auditable rather than a number to be trusted:
 * a manager looking at "467,50 €" can see which grid cell, which minimum and
 * which fee each part came from. It is a stable machine key, not prose —
 * the human explanation is `$label`.
 *
 * `$isManual` marks a line a manager typed or edited by hand. Such a line is
 * **never recalculated** (§6.12): if the dates or the head count change
 * later, automatic lines are recomputed and manual ones are left exactly as
 * they were. The flag lives here, on the line, because that is the only
 * place that survives being snapshotted.
 */
final class PriceLine
{
    public const RULE_BASE = 'base';
    public const RULE_FEE_FIXED = 'fee_fixed';
    public const RULE_FEE_PER_PERSON = 'fee_per_person';
    /** A meter fee, listed for information — never part of the total. */
    public const RULE_FEE_METER = 'fee_meter';
    public const RULE_MANUAL = 'manual';

    /**
     * @param int $quantity Whole units — nights, person-nights, people, rooms. Never a fraction: the engine has no pro-rata, by design.
     * @param int|null $unitPriceCents Null for a line whose amount is not a quantity × price product (a flat fee), so a template can tell "1 × 50 €" from "50 €".
     * @param bool $isInformational True for a line shown but excluded from the total — meter fees, which are settled on real readings after the stay.
     * @param array<string, string|int|null> $meta Extra facts worth snapshotting (period id, category id, fee id, whether a minimum applied).
     */
    public function __construct(
        public readonly string $label,
        public readonly int $quantity,
        public readonly ?int $unitPriceCents,
        public readonly int $amountCents,
        public readonly string $rule,
        public readonly bool $isInformational = false,
        public readonly bool $isManual = false,
        public readonly array $meta = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unitPriceCents,
            'amount_cents' => $this->amountCents,
            'rule' => $this->rule,
            'is_informational' => $this->isInformational,
            'is_manual' => $this->isManual,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            quantity: (int) $data['quantity'],
            unitPriceCents: isset($data['unit_price_cents']) ? (int) $data['unit_price_cents'] : null,
            amountCents: (int) $data['amount_cents'],
            rule: (string) $data['rule'],
            isInformational: (bool) ($data['is_informational'] ?? false),
            isManual: (bool) ($data['is_manual'] ?? false),
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : []
        );
    }
}
