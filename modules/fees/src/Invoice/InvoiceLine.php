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
 * **Nothing here is decided from a list of known references.** A reference
 * the site has never seen must not block a reading, so its nature is read
 * off its own shape (`nature()`): a negative unit price is a reduction, a
 * line with no nominative list is a global adjustment, a positive one with
 * a list is a membership fee. `COT_iAM_LOCAL` is therefore read correctly
 * by a parser that has never heard of it, which is the whole point.
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
     * The absence of a nominative list is tested FIRST, and that ordering
     * is the whole rule: the deposit deduction and a brevet reduction are
     * both negative, and what tells them apart is that one names nobody.
     * A line with no list is about the invoice as a whole, whichever way
     * its sign points.
     */
    public function nature(): string
    {
        if ($this->people === []) {
            return self::NATURE_ADJUSTMENT;
        }

        return $this->unitPriceCents < 0 ? self::NATURE_REDUCTION : self::NATURE_FEE;
    }

    /**
     * The identity a page-break repetition is recognised by: the same
     * (reference, section, unit price, quantity, amount) seen twice is one
     * line whose lists are merged, not two lines.
     *
     * Deliberately a tuple of the line's own values rather than a
     * page-boundary detection: the second occurrence is genuinely the same
     * line, and a reader looking for "where does the page end" is reading
     * a layout instead of a document.
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
