<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * A deliberately tiny xorshift32, not `mt_rand()`.
 *
 * The generated CSVs are committed and compared byte-for-byte by
 * `generate.php --check`, so the sequence must be identical on every machine
 * and every PHP build, forever. `mt_rand()` is seeded-deterministic today, but
 * that is an implementation property of the engine rather than a promise to
 * callers: it has changed once already (the PHP 7.1 modulo-bias fix) and the
 * check would then fail on a PHP upgrade with nothing in the dataset having
 * moved. Thirty lines of arithmetic here cost less than that diagnosis.
 *
 * Not a cryptographic generator, and never used as one: this seeds fake
 * children, not keys.
 */
final class Rng
{
    private const MASK = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        // Zero is xorshift's fixed point — it would return 0 forever.
        $this->state = ($seed & self::MASK) !== 0 ? $seed & self::MASK : 0x2545F491;
    }

    /** Next raw 32-bit value. */
    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & self::MASK;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & self::MASK;
        $this->state = $x & self::MASK;

        return $this->state;
    }

    /** Uniform-enough integer in [$min, $max], both inclusive. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + $this->next() % ($max - $min + 1);
    }

    /** True $percent times out of 100. */
    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * Fisher-Yates, so a shuffled roster is reproducible. PHP's own
     * `shuffle()` uses the global Mt19937 state, which this class exists to
     * stay away from.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
