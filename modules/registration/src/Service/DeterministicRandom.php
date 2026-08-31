<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * A tiny linear congruential generator, so the optimiser's random restarts
 * are reproducible (roadmap IT-18).
 *
 * NOT mt_rand()/shuffle(): those share one global generator with whatever
 * else ran in the same request, so seeding it makes a run reproducible only
 * as long as nothing else draws from it — which is not a property anybody
 * can hold on to. An instance of its own has no such coupling.
 *
 * NOT a source of randomness for anything that matters, either: this
 * shuffles a list of candidates and nothing else. Anything security-related
 * uses random_bytes() (SECURITY.md), and this class must never be reached
 * for by such a caller — hence the deliberately unappealing name.
 *
 * The constants are glibc's, the ones every LCG example uses; the point is
 * to be boring and identical everywhere, not to be a good generator.
 */
final class DeterministicRandom
{
    private const MODULUS = 2147483648;
    private const MULTIPLIER = 1103515245;
    private const INCREMENT = 12345;

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = abs($seed) % self::MODULUS;
    }

    public function next(int $bound): int
    {
        $this->state = (self::MULTIPLIER * $this->state + self::INCREMENT) % self::MODULUS;

        return $bound > 0 ? $this->state % $bound : 0;
    }

    /**
     * Fisher-Yates, so the same seed and the same list always give the same
     * order — which is what the determinism test pins.
     *
     * @template T
     * @param array<int, T> $values
     * @return array<int, T>
     */
    public function shuffled(array $values): array
    {
        $values = array_values($values);
        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->next($i + 1);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }

        return $values;
    }
}
