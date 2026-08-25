<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/**
 * One person the invoice and the roster snapshot disagree about.
 *
 * Five shapes, and they are not variations of one another — each names a
 * different thing to go and do:
 *
 * - `BILLED_BUT_LEAVING` — the federation billed someone Desk still holds
 *   but who is marked leaving. The federation is not wrong; **Desk is out
 *   of date**, and the money is real until it is corrected there.
 * - `NOT_ON_INVOICE` — Desk holds them, in a section this invoice bills,
 *   on a household tariff, and no line names them. The unit is
 *   under-billed and it will come back in the regularisation.
 * - `DIFFERENT_SECTION` — billed under one section, held in another.
 *   **This costs nothing**: the tariff is the same either way. It is
 *   reported because a section that has drifted is worth knowing about,
 *   and it is never given a figure, because inventing one would put a
 *   euro amount on a difference that is not money.
 * - `DIFFERENT_CATEGORY` — billed on a household tariff other than the one
 *   Desk encodes. Costs the difference between the two, read off this
 *   invoice's own prices.
 * - `BREVET_REDUCTION_MISSING` — the snapshot says breveté and no
 *   reduction line names them, on a document that applies the reduction to
 *   other people. Costs what the reduction would have been worth.
 *
 * `$costCents` is signed the same way everywhere: **positive means the
 * unit was billed more than the roster justifies**. Null means the site
 * will not put a figure on it — either because the kind has none by
 * nature (see {@see costsNothingByNature()}) or because this document's
 * lines do not carry the price it would take.
 */
final class NominativeDiscrepancy
{
    public const BILLED_BUT_LEAVING = 'billed_but_leaving';
    public const NOT_ON_INVOICE = 'not_on_invoice';
    public const DIFFERENT_SECTION = 'different_section';
    public const DIFFERENT_CATEGORY = 'different_category';
    public const BREVET_REDUCTION_MISSING = 'brevet_reduction_missing';

    /** A section discrepancy has no amount — not an unknown one, none at all. */
    private const WITHOUT_COST = [self::DIFFERENT_SECTION];

    /**
     * The one order the screen, the export and the service all use.
     *
     * Not alphabetical, and not the order they happen to be found in:
     * "facturé mais parti" and "membre absent" are what a treasurer acts
     * on, and they come first for that reason. Declared here rather than
     * in a controller so the spreadsheet cannot end up sorted differently
     * from the screen it was exported from.
     */
    public const ORDER = [
        self::BILLED_BUT_LEAVING,
        self::NOT_ON_INVOICE,
        self::DIFFERENT_SECTION,
        self::DIFFERENT_CATEGORY,
        self::BREVET_REDUCTION_MISSING,
    ];

    /** Its rank in {@see ORDER}; an unknown kind sorts last rather than first. */
    public function rank(): int
    {
        $rank = array_search($this->kind, self::ORDER, true);

        return $rank === false ? count(self::ORDER) : $rank;
    }

    public function __construct(
        public readonly string $kind,
        public readonly int $memberId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?string $billedSectionLabel,
        public readonly ?string $rosterSectionLabel,
        public readonly ?string $billedCategoryLabel,
        public readonly ?string $rosterCategoryLabel,
        public readonly ?int $costCents
    ) {
    }

    /**
     * True when this kind of difference is not money — as opposed to money
     * the site could not work out. The screen says "sans incidence sur le
     * montant" for the first and stays silent for the second, and running
     * the two together is how a report starts accusing a federation of an
     * error that costs nobody anything.
     */
    public function costsNothingByNature(): bool
    {
        return in_array($this->kind, self::WITHOUT_COST, true);
    }

    /**
     * What `|display_name_full` needs, without this module inventing a
     * name rule of its own (AGENTS.md § Display name convention).
     *
     * @return array{first_name: string, last_name: string, totem: ?string}
     */
    public function nameParts(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'totem' => $this->totem,
        ];
    }
}
