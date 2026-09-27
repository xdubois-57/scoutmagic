<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * The three household tariffs the federation publishes, in cents: normal
 * (one member), couple and family (each per person). `year` is the
 * `YYYY-YYYY` heading they were published under, when the page gave one.
 */
final class FederalScale
{
    public function __construct(
        public readonly int $normalCents,
        public readonly int $coupleCents,
        public readonly int $familyCents,
        public readonly ?string $year = null,
    ) {
    }

    /** Same three amounts; the checker compares the year on its own. */
    public function sameAmountsAs(self $other): bool
    {
        return $this->normalCents === $other->normalCents
            && $this->coupleCents === $other->coupleCents
            && $this->familyCents === $other->familyCents;
    }

    public function describe(): string
    {
        return sprintf(
            '%snormal %s, couple %s, family %s',
            $this->year !== null ? $this->year . ': ' : '',
            self::euros($this->normalCents),
            self::euros($this->coupleCents),
            self::euros($this->familyCents)
        );
    }

    public static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '') . ' €';
    }
}
