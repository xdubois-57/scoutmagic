<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

use Core\Service\TextNormalizerService;

/**
 * A name → members.id table, indexed BOTH WAYS: « Nom Prénom » and
 * « Prénom Nom ». The name is the only thing a federation PDF carries — no
 * Desk identifier, no date of birth — so this is the whole of what matching
 * has to work with.
 *
 * **It holds every scout year, not the effective one.** A tax certificate
 * covers the year just gone: it often concerns somebody who has since left
 * and is absent from the current roster. Restricting the table to the year
 * in progress would deny exactly the families who most need the document,
 * and who have no page on the site to go and fetch it from.
 *
 * **A name that resolves to several members stays several.** `lookup()`
 * returns the whole candidate list; the caller reads the count as the
 * state (Value\MatchState). Nothing here picks one.
 *
 * Keys go through `Core\Service\TextNormalizerService::fold()` — this
 * project's single case- and accent-insensitive comparison form — rather
 * than a lowercase/trim of this module's own. Two normalisers written "the
 * same way" are one edit apart from disagreeing, and the failure is silent:
 * the lookup that misses reports « aucun membre de ce nom » rather than
 * failing. Folding also collapses punctuation, so « Herremans-Dupuis »
 * matches « Herremans Dupuis » — and where it merges two genuinely
 * different spellings, the result is an ambiguity a human resolves, never a
 * wrong match nobody sees.
 */
final class MemberNameDirectory
{
    /** @var array<string, array<int, true>> folded name => set of members.id */
    private array $byName = [];

    /**
     * Both orders, always. Desk holds first and last name in separate
     * columns; a certificate prints them in whichever order its template
     * chose, and the two are not distinguishable once printed.
     */
    public function add(int $memberId, string $firstName, string $lastName): void
    {
        foreach ([$lastName . ' ' . $firstName, $firstName . ' ' . $lastName] as $spelling) {
            $key = self::key($spelling);
            if ($key === '') {
                continue;
            }
            $this->byName[$key][$memberId] = true;
        }
    }

    /**
     * @return list<int> every members.id this text resolves to, ascending
     *                   and without duplicates; empty when it names nobody
     */
    public function lookup(string $text): array
    {
        $key = self::key($text);
        if ($key === '' || !isset($this->byName[$key])) {
            return [];
        }

        $ids = array_keys($this->byName[$key]);
        sort($ids);

        return $ids;
    }

    /** True when nothing was ever added — a site with no roster at all. */
    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /** How many distinct spellings are indexed. Diagnostics only. */
    public function size(): int
    {
        return count($this->byName);
    }

    private static function key(string $value): string
    {
        return TextNormalizerService::fold($value);
    }
}
