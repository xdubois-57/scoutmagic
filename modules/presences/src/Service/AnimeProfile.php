<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

/**
 * One animé's year, as the page that prepares a phone call reads it.
 *
 * **The rate never travels alone.** A figure of 21 % means nothing until
 * it sits beside the section's own average — a section at 40 % and a
 * section at 90 % are two different conversations — so the comparison is
 * part of the object rather than something a template may or may not
 * remember to fetch.
 */
final class AnimeProfile
{
    /**
     * @param list<AnimeMonth> $months oldest first
     * @param list<AnimeHistoryEntry> $history most recent first, capped
     */
    public function __construct(
        public readonly int $memberId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $totem,
        public readonly int $sectionId,
        public readonly string $sectionLabel,
        public readonly ?string $sectionColor,
        public readonly int $rate,
        public readonly int $sectionAverageRate,
        public readonly int $present,
        public readonly int $pointedEvents,
        public readonly array $months,
        public readonly array $history,
        /** Whether the history shown is shorter than the year — the page says so. */
        public readonly bool $historyTruncated
    ) {
    }

    public function displayName(): string
    {
        return $this->lastName . ', ' . $this->firstName;
    }

    public function tone(): string
    {
        return RegisterAnime::toneForRate($this->rate);
    }
}
