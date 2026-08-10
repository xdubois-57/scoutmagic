<?php

declare(strict_types=1);

namespace Core\Member;

/**
 * Suggested fee category derived from how many members Core\Member\
 * FeeEstimationService found at the same normalized address — never
 * applied automatically (ARCHITECTURE.md §8): a human always sees, and
 * can override, both the category and the household size it came from.
 */
enum HouseholdFeeCategory: string
{
    case NORMAL = 'normal';
    case COUPLE = 'couple';
    case FAMILY = 'family';

    public static function fromHouseholdSize(int $size): self
    {
        return match (true) {
            $size >= 3 => self::FAMILY,
            $size === 2 => self::COUPLE,
            default => self::NORMAL,
        };
    }
}
