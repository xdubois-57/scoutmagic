<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * The two sorts of inventory item (#708, IT-10).
 *
 * **`QUANTITY` is what is counted**, with an expected number — « Chaises —
 * 40 », « Clés — 3 ». **`YES_NO` is what is observed**, and its label is
 * written as what must be true — « Cuisine propre », « Chauffage coupé » —
 * so the expected answer is always « Oui » and the item needs no number.
 *
 * Copied into each booking with the label at confirmation: a frozen copy
 * does not change when the asset's template does.
 */
enum InventoryKind: string
{
    case QUANTITY = 'quantity';
    case YES_NO = 'yes_no';

    /** Expected count of a new quantity item when none is given. */
    public const DEFAULT_COUNT = 1;

    public function label(): string
    {
        return match ($this) {
            self::QUANTITY => 'Quantité',
            self::YES_NO => 'Oui / Non',
        };
    }

    /**
     * What the inventory expects to find, in words: « 40 » or « Oui ».
     * A quantity row frozen before the sorts existed has no count, and
     * says nothing rather than inventing one.
     */
    public function expectation(?int $expectedCount): ?string
    {
        return match ($this) {
            self::QUANTITY => $expectedCount !== null ? (string) $expectedCount : null,
            self::YES_NO => 'Oui',
        };
    }

    /**
     * The count to store for this sort: the one given, or 1, for a
     * quantity; none for a yes/no item, whose answer is never a number.
     * Whether a given count is acceptable is the service's call.
     */
    public function normaliseCount(?int $expectedCount): ?int
    {
        return $this === self::QUANTITY ? ($expectedCount ?? self::DEFAULT_COUNT) : null;
    }
}
