<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

/**
 * Core\Member\FeeEstimationService's result — carries the household size
 * alongside the suggested category so a calling screen can explain where
 * the suggestion comes from (never present it as an unexplained decision).
 *
 * $addressUsable is the third thing it carries, and the reason the
 * constructor is private: an address that normalizes to nothing produces
 * NORMAL with a size of 0, which reads exactly like a genuine household of
 * one and is not one — it is "the site cannot say". A caller that treats
 * the two alike reports a member with no exploitable address as compliant.
 * The two named constructors below make that state impossible to produce
 * by accident, and impossible to read without noticing.
 */
final class FeeEstimate
{
    private function __construct(
        public readonly HouseholdFeeCategory $category,
        public readonly int $householdSize,
        public readonly bool $addressUsable
    ) {
    }

    /** A real answer, from a real address: this many people, hence this category. */
    public static function forHouseholdSize(int $size): self
    {
        return new self(HouseholdFeeCategory::fromHouseholdSize($size), $size, true);
    }

    /**
     * No answer: the address was empty or normalized to nothing, so no
     * household could be looked up. The category and size are the historic
     * NORMAL/0 so existing renderings keep working, but $addressUsable is
     * false and that is the only field a new caller should trust.
     */
    public static function addressNotUsable(): self
    {
        return new self(HouseholdFeeCategory::NORMAL, 0, false);
    }
}
