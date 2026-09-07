<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * A home several generated members share, from the generator's side.
 *
 * This is **not** `Core\Member\Household\Household`, which the site derives
 * from the imported data. This one is the intent: the surname, the address
 * and the parent mailbox that PopulationBuilder hands to everybody who lives
 * here, so that when the export is read back the site's own
 * `AddressNormalizer` groups them into one household of its own accord. If
 * this class and that one ever disagree, the site is right and this is the
 * bug — nothing here is allowed to be a second opinion about who lives
 * together.
 *
 * `$members` is the Tiers of everyone assigned here across all three years,
 * not the household's size in any one of them: somebody who left in A2 stays
 * on the list, because a newcomer must not be given their room. Only
 * {@see isFull()} reads it.
 */
final class Household
{
    /** @var list<string> Tiers of every member ever assigned to this home */
    public array $members = [];

    /**
     * @param int $targetSize how many people this home is meant to end up
     *                        holding, drawn once from
     *                        UnitBlueprint::HOUSEHOLD_TARGET_SIZES
     */
    public function __construct(
        public readonly string $lastName,
        public readonly PostalAddress $address,
        public readonly string $email,
        public readonly int $targetSize,
        public readonly ?PostalAddress $secondAddress = null,
    ) {
    }

    public function isFull(): bool
    {
        return count($this->members) >= $this->targetSize;
    }
}
