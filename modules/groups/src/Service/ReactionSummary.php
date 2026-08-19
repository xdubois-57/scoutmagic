<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Support\Reactions;

/**
 * One item's reactions, as the templates need them: the non-zero counts in
 * the fixed display order, the total, and which one (if any) is this
 * viewer's own — so the picker can show it as selected and a second click
 * on it reads as "remove".
 *
 * The emoji character is resolved here, once, from Support\Reactions'
 * constant map, so no template ever has to know a key exists.
 */
final class ReactionSummary
{
    /**
     * @param array<int, array{key: string, emoji: string, count: int, is_own: bool}> $entries
     */
    private function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly ?string $ownKey
    ) {
    }

    /**
     * @param array<string, int> $counts reaction key => count, as
     *        Repository\ReactionRepository::countsFor() returns it
     *        (non-zero entries only, in no particular order)
     */
    public static function build(array $counts, ?string $ownKey): self
    {
        $entries = [];
        $total = 0;

        // Iterating the constant map rather than $counts is what puts the
        // entries in the fixed display order regardless of what order the
        // database happened to group them in — and it silently drops any
        // key that is no longer part of the set.
        foreach (Reactions::EMOJI as $key => $emoji) {
            $count = $counts[$key] ?? 0;
            if ($count <= 0) {
                continue;
            }

            $entries[] = [
                'key' => $key,
                'emoji' => $emoji,
                'count' => $count,
                'is_own' => $key === $ownKey,
            ];
            $total += $count;
        }

        return new self($entries, $total, $ownKey);
    }
}
