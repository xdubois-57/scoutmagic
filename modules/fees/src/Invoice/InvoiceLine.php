<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Invoice;

/**
 * One tariff line: a reference, what it costs, how many, and — usually —
 * who.
 *
 * **Reading never requires a known reference.** A reference the site has
 * never seen must not block a reading. A negative unit price
 * is a reduction; membership summaries remain fees even without names.
 * The known global advance deduction has its own business reference.
 */
final class InvoiceLine
{
    public const NATURE_FEE = 'fee';
    public const NATURE_REDUCTION = 'reduction';
    public const NATURE_ADJUSTMENT = 'adjustment';

    /** @param InvoicePerson[] $people */
    public function __construct(
        public readonly string $reference,
        public readonly string $descriptor,
        public readonly ?string $sectionCode,
        public readonly int $unitPriceCents,
        public readonly int $quantity,
        public readonly int $amountCents,
        public readonly array $people = []
    ) {
    }

    /**
     * Names determine which checks are possible, not the nature of a fee.
     * The global advance deduction can carry either a negative price or a
     * negative quantity, unlike section summaries on advance invoices.
     */
    public function nature(): string
    {
        if (strtoupper($this->reference) === 'COT_ACOMPTE' && $this->sectionCode === null) {
            return self::NATURE_ADJUSTMENT;
        }

        return $this->unitPriceCents < 0 ? self::NATURE_REDUCTION : self::NATURE_FEE;
    }

    /**
     * Equal values are necessary for a page continuation, but insufficient:
     * the parser also requires a page boundary and nominative evidence.
     */
    public function tuple(): string
    {
        return implode('|', [
            $this->reference,
            $this->sectionCode ?? '',
            $this->unitPriceCents,
            $this->quantity,
            $this->amountCents,
        ]);
    }

    /** @param InvoicePerson[] $people */
    public function withPeople(array $people): self
    {
        return new self(
            $this->reference,
            $this->descriptor,
            $this->sectionCode,
            $this->unitPriceCents,
            $this->quantity,
            $this->amountCents,
            $people
        );
    }
}
