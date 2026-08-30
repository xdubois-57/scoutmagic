<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * One certificate as it was READ out of the deposited PDF — a page range, a
 * name, and the members that name resolves to. Nothing here has been
 * written anywhere yet, and nothing here is a decision: the verification
 * screen turns it into one.
 *
 * `$memberIds` carries every candidate rather than a single answer,
 * because the count IS the state (see MatchState): none, one, or several.
 * Collapsing several to one here would move the silent-wrong-family choice
 * from the old site's parser into this one.
 */
final class ReadAttestation
{
    /**
     * @param int          $firstPage 1-based, inclusive
     * @param int          $lastPage  1-based, inclusive
     * @param string|null  $readName  the text field that matched a member,
     *                                or the page's best candidate line when
     *                                none did — null when the page carries
     *                                no usable text at all
     * @param list<int>    $memberIds every members.id this name resolves
     *                                to, ascending, without duplicates
     */
    public function __construct(
        public readonly int $firstPage,
        public readonly int $lastPage,
        public readonly ?string $readName,
        public readonly array $memberIds
    ) {
    }

    public function state(): MatchState
    {
        return match (count($this->memberIds)) {
            0 => MatchState::Unmatched,
            1 => MatchState::Matched,
            default => MatchState::Ambiguous,
        };
    }

    /** The single matched member, or null when there is not exactly one. */
    public function matchedMemberId(): ?int
    {
        return count($this->memberIds) === 1 ? $this->memberIds[0] : null;
    }

    public function pageCount(): int
    {
        return $this->lastPage - $this->firstPage + 1;
    }

    /** « 1–2 », or « 3 » for a one-page certificate. */
    public function pageRangeLabel(): string
    {
        return $this->firstPage === $this->lastPage
            ? (string) $this->firstPage
            : $this->firstPage . '–' . $this->lastPage;
    }
}
