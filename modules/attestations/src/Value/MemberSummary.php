<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * What the verification screen needs to show about one member: the name to
 * compare against what the PDF printed, and the main function to filter on.
 *
 * **The full name, never the display name.** Everywhere else this site
 * writes `totem ?? first_name`; here the reader is comparing two spellings
 * of the same person — the one the federation printed and the one Desk
 * holds — and a totem would compare against nothing. Same reason
 * `Core\Member\MemberPageService` uses `full_name` for a section's
 * responsable.
 *
 * `$functionLabel` is null for somebody Desk gave no main function, which is
 * ordinary for a member who has left: their line still has to be
 * distributable, so a missing function is never a reason to hide a row.
 */
final class MemberSummary
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $fullName,
        public readonly ?string $functionLabel,
        public readonly string $scoutYearLabel
    ) {
    }

    /**
     * What the function filter groups by. A member with no function is
     * grouped under nothing rather than under an invented label — the
     * screen keeps their row visible whatever the filter says.
     */
    public function functionKey(): ?string
    {
        return $this->functionLabel;
    }
}
