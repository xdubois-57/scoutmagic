<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

/**
 * Disjoint sets over string keys — the smallest thing that answers "are
 * these two in the same household?" once two different links can put them
 * there (roadmap IT-18).
 *
 * Two links feed it: the sibling ids declared on a registration request,
 * and the « même adresse » link between branch-changing members. They
 * compose — a request declaring a sibling who shares an address with a
 * third child puts all three in one group — and doing that with nested
 * loops would be a bug waiting for the third child.
 *
 * No path compression by rank: these sets hold a unit's roster, not a
 * graph, and the plain version is the one a reader can check by eye.
 */
final class UnionFind
{
    /** @var array<string, string> */
    private array $parent = [];

    public function find(string $key): string
    {
        $this->parent[$key] ??= $key;

        $root = $key;
        while ($this->parent[$root] !== $root) {
            $root = $this->parent[$root];
        }

        // Flatten on the way back, so the next lookup is one hop.
        while ($this->parent[$key] !== $root) {
            [$key, $this->parent[$key]] = [$this->parent[$key], $root];
        }

        return $root;
    }

    public function union(string $left, string $right): void
    {
        $a = $this->find($left);
        $b = $this->find($right);
        if ($a === $b) {
            return;
        }

        // The smaller key always wins, so a group's representative does not
        // depend on the order the links were fed in — which is what makes
        // the optimiser reproducible.
        if ($a < $b) {
            $this->parent[$b] = $a;
        } else {
            $this->parent[$a] = $b;
        }
    }
}
