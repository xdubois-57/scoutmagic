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
 * touches code. The charter that decides which value a topic carries is
 * design.md §7.11.
 *
 * **A whole number, ascending — lower is offered first — or `off`.** This
 * used to be an enum of exactly `1`/`2`/`3`/`off`, and the three ranks
 * were not enough the first time somebody needed a topic to come before
 * every other: `installer-application` has to lead, because the whole
 * chain that follows — install the application, then be offered push
 * notifications (§8.110) — starts with it, and « somewhere among the
 * twenty-four `1`s » is not leading. An enum would have to grow a case
 * for each such decision, in code, which is exactly what declaring the
 * order in the topic was meant to avoid. An integer needs no case: the
 * shipped corpus still writes 1 and 3 and means what it always meant, and
 * 0 now exists without anything having been renumbered.
 *
 * **`off` is not a big number, it is the absence of a rank.** A topic
 * nobody discovers — account hygiene, a legal obligation, a server
 * operation — is consulted at the moment it is needed and would only ever
 * waste a card; it is dropped before anything is sorted rather than sorted
 * last, which is why `rank()` refuses to answer for it.
 *
 * **2 is the default and is deliberately never written**: an absent key
 * means it, and a hundred and thirty `discovery: 2` lines would be noise
 * in every file for no information at all.
 *
 * Not an enum any more, so this is a plain object in the serialized help
 * index (Core\Help\HelpRegistry's cache) — it stays in that cache's
 * allowed-classes list, and the format mark there was bumped for the
 * change of shape.
 */
final class DiscoveryPriority
{
    /**
     * What a topic with no `discovery:` line means. The middle of the
     * shipped corpus's scale, so an ordinary topic is offered after the
     * ones somebody deliberately promoted and before the ones they
     * deliberately demoted.
     */
    public const DEFAULT_RANK = 2;

    /**
     * @param ?int $rank null for `off` — see the class docblock.
     */
    private function __construct(private readonly ?int $rank)
    {
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_RANK);
    }

    public static function off(): self
    {
        return new self(null);
    }

    public static function ofRank(int $rank): self
    {
        return new self($rank);
    }

    /**
     * Reads one front-matter value, or null when it is neither `off` nor
     * a whole number — which the parser turns into a HelpException naming
     * the file, the same posture as an unknown `role_min`.
     *
     * Deliberately strict about the spelling: ` 1 ` is trimmed by the
     * parser before it gets here, but `1.5`, `1er` and an empty string are
     * refused rather than read as 1, because each of them is a typo whose
     * silent reading nobody would ever notice.
     */
    public static function tryFrom(string $raw): ?self
    {
        if ($raw === 'off') {
            return self::off();
        }

        return preg_match('/^-?\d+$/', $raw) === 1 ? self::ofRank((int) $raw) : null;
    }

    /**
     * Whether this topic is never offered as a tip at all.
     */
    public function isOff(): bool
    {
        return $this->rank === null;
    }

    /**
     * Sort rank, ascending — 0 before 1 before 2 before 3.
     *
     * Never called for an `off` topic: Core\Help\Discovery\DiscoveryService
     * drops those before it orders anything, and answering some number
     * here would let a caller that forgot to drop them sort them into the
     * running order instead of failing.
     *
     * @throws \LogicException when this priority is `off`
     */
    public function rank(): int
    {
        if ($this->rank === null) {
            throw new \LogicException('An `off` help topic has no rank — it is never part of a running order.');
        }

        return $this->rank;
    }

    /**
     * The value as a topic would write it, for a message a human reads.
     */
    public function declaredValue(): string
    {
        return $this->rank === null ? 'off' : (string) $this->rank;
    }
}
