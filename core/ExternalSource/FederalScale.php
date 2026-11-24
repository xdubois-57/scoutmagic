<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * The five per-person tariffs and the annual paid guest window.
 * `year` is the `YYYY-YYYY` heading they were published under, when given.
 */
final class FederalScale
{
    public function __construct(
        public readonly int $normalCents,
        public readonly int $coupleCents,
        public readonly int $familyCents,
        public readonly int $iamaCents,
        public readonly int $guestCents,
        public readonly string $guestFromDayMonth,
        public readonly string $guestUntilDayMonth,
        public readonly ?string $year = null,
    ) {
    }

    /** The checker compares the year and annual window separately. */
    public function sameAmountsAs(self $other): bool
    {
        return $this->normalCents === $other->normalCents
            && $this->coupleCents === $other->coupleCents
            && $this->familyCents === $other->familyCents
            && $this->iamaCents === $other->iamaCents
            && $this->guestCents === $other->guestCents;
    }

    public function sameGuestPeriodAs(self $other): bool
    {
        return $this->guestFromDayMonth === $other->guestFromDayMonth
            && $this->guestUntilDayMonth === $other->guestUntilDayMonth;
    }

    public function describe(): string
    {
        return sprintf(
            '%snormale %s, couple %s, familiale %s, IAmA %s, invités %s, période Invités %s–%s inclus',
            $this->year !== null ? $this->year . ': ' : '',
            self::euros($this->normalCents),
            self::euros($this->coupleCents),
            self::euros($this->familyCents),
            self::euros($this->iamaCents),
            self::euros($this->guestCents),
            $this->guestFromDayMonth,
            $this->guestUntilDayMonth
        );
    }

    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '') . ' €';
    }
}
