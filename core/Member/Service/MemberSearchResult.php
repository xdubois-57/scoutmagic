<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Service;

/**
 * Lightweight, decrypted view of a member for the search results list.
 * (The detail card uses the full MemberProfile from MemberService.)
 */
class MemberSearchResult
{
    public function __construct(
        public readonly int $memberYearId,
        // The PERSISTENT identity. Results are grouped on it, never on a
        // name: an enfant present for five years is one person with five
        // annual rows, and two children can share a name.
        public readonly int $memberId,
        public readonly int $scoutYearId,
        public readonly string $scoutYearLabel,
        // The year's own start_date, because scout year IDS ARE NOT
        // CHRONOLOGICAL: `ScoutYearService::ensureYear()` can create a
        // past year after a later one, so ordering on the id puts the
        // wrong year first — which is what a grouped row displays.
        public readonly string $scoutYearStartDate,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $totem,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $mobile,
        public readonly ?string $sectionName,
        public readonly ?string $functionLabel,
        public readonly ?string $addressText,
        public readonly bool $isActive
    ) {
    }

    /**
     * Two-letter initials: first letter of first name + first letter of last name.
     */
    public function initials(): string
    {
        $f = $this->firstName !== '' ? mb_substr($this->firstName, 0, 1, 'UTF-8') : '';
        $l = $this->lastName !== '' ? mb_substr($this->lastName, 0, 1, 'UTF-8') : '';

        return mb_strtoupper($f . $l, 'UTF-8');
    }

    /**
     * All searchable fields concatenated (raw values).
     */
    public function haystack(): string
    {
        return implode(' ', array_filter([
            $this->lastName,
            $this->firstName,
            $this->totem,
            $this->email,
            $this->phone,
            $this->mobile,
            $this->sectionName,
            $this->functionLabel,
            $this->addressText,
        ]));
    }
}
