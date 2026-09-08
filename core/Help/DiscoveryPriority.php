<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Help;

/**
 * How eagerly a help topic is offered as a « Le saviez-vous ? » tip
 * (ARCHITECTURE.md §8.95) — the optional `discovery:` line of a topic's
 * front matter.
 *
 * The order is editorial and declared by the topic itself, exactly as
 * `role_min` and `paths` already are: adding or re-ranking help never
 * touches code. `High` is a capability somebody can plainly not know
 * about and that saves them time, `Low` is a peripheral variant of one,
 * and `Off` is a subject nobody discovers — account hygiene, a legal
 * obligation, a server operation — which is consulted at the moment it is
 * needed and would only ever waste a card. The charter that decides which
 * is which is design.md §7.11.
 *
 * `Normal` is the default and is deliberately never written: an absent
 * key means it, and a hundred and twenty `discovery: 2` lines would be
 * noise in every file for no information at all.
 */
enum DiscoveryPriority: string
{
    case High = '1';
    case Normal = '2';
    case Low = '3';
    case Off = 'off';

    /**
     * Sort rank, ascending — 1 before 2 before 3.
     *
     * `Off` is never sorted: Core\Help\Discovery\DiscoveryService drops
     * those topics before it orders anything, so the value here only has
     * to keep them out of the way if that ever changes.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
            self::Off => PHP_INT_MAX,
        };
    }
}
