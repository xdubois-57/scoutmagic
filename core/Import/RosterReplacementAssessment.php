<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * What a Desk CSV would do to a scout year's roster, counted before
 * anything is written.
 *
 * Everything here is a number, a flag or a section code — never a name.
 * The screen it feeds states consequences ("216 membres seraient
 * désactivés", "seule la section Baladins 1 figure dans ce fichier")
 * rather than asking a generic question, and the security journal entry
 * built from it carries the same counters and nothing else.
 */
final class RosterReplacementAssessment
{
    /**
     * @param string[] $fileSectionCodes Desk codes the file names, sorted
     * @param string[] $sectionCodesGoingInactive Desk codes of sections the file would empty
     */
    public function __construct(
        public readonly RosterReplacementVerdict $verdict,
        public readonly int $fileMemberCount,
        public readonly int $fileLineCount,
        public readonly array $fileSectionCodes,
        public readonly int $rosterMemberCount,
        public readonly int $rosterSectionCount,
        public readonly ?\DateTimeImmutable $rosterImportedAt,
        public readonly int $deactivatedCount,
        public readonly array $sectionCodesGoingInactive,
        public readonly int $unitStaffCount,
        public readonly int $unitStaffLostCount,
        public readonly int $adminCountAfter,
        public readonly bool $importerLosesAdmin,
        public readonly bool $hasSuperAdminAccount,
        /**
         * Whether the year being imported is the one access is resolved
         * against (`Core\Security\RoleResolver`). False for a year
         * prepared in advance, where every access consequence on this
         * screen would be a statement about a future nobody has chosen
         * yet — so the screen states none of them.
         */
        public readonly bool $yearBearsAccess = true
    ) {
    }

    /** Whether the import may run as asked, with nothing to confirm. */
    public function isClear(): bool
    {
        return $this->verdict === RosterReplacementVerdict::ALLOWED;
    }

    /** Whether the whole Staff d'Unité would disappear at once. */
    public function unitStaffWipedOut(): bool
    {
        return $this->unitStaffCount > 0 && $this->unitStaffLostCount === $this->unitStaffCount;
    }

    /**
     * The share of the year's members the file drops, as whole percent —
     * 0 when the year has no roster yet, which is also the one case where
     * no proportion could mean anything.
     */
    public function deactivationPercent(): int
    {
        if ($this->rosterMemberCount === 0) {
            return 0;
        }

        return (int) round($this->deactivatedCount * 100 / $this->rosterMemberCount);
    }

    /**
     * The counters a `security` journal entry carries — deliberately the
     * whole of what is journaled about a barrier, and deliberately
     * without a single name or Desk identifier (SECURITY.md §11).
     *
     * @return array<string, int|string|bool>
     */
    public function journalContext(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'file_member_count' => $this->fileMemberCount,
            'file_line_count' => $this->fileLineCount,
            'file_section_count' => count($this->fileSectionCodes),
            'roster_member_count' => $this->rosterMemberCount,
            'roster_section_count' => $this->rosterSectionCount,
            'deactivated_count' => $this->deactivatedCount,
            'sections_going_inactive' => count($this->sectionCodesGoingInactive),
            'unit_staff_lost_count' => $this->unitStaffLostCount,
            'admin_count_after' => $this->adminCountAfter,
            'has_super_admin' => $this->hasSuperAdminAccount,
            'year_bears_access' => $this->yearBearsAccess,
        ];
    }
}
