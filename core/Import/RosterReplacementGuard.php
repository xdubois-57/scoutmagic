<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

use Core\ScoutYear\ScoutYearResolver;

/**
 * The barrier against an export filtered on one section.
 *
 * A Desk export offers a section filter, and picking one instead of "tous
 * les membres" produces a perfectly valid CSV holding forty of the unit's
 * two hundred and sixty people. Imported, it deactivates everyone else,
 * empties the Staff d'Unité, and takes the `admin` role away from the
 * person who launched it — who then no longer has the Import,
 * Configuration or Maintenance pages they would need to put it back. The
 * only way out is an `is_super_admin` account, which a chef d'unité who
 * is admin purely by Desk function does not have.
 *
 * So this runs **before the transaction**, in the same place and with the
 * same posture as the header validation `DeskCsvParser` already performs:
 * refuse before writing, never repair after.
 *
 * Four signals, one of them decisive:
 *
 * - **One section in the file against several in the roster.** No
 *   threshold at all, and near enough no false positive — a unit really
 *   reduced to one section has one section in its roster too.
 * - **The share of the year's active members the file drops.** A
 *   commented constant below, never a setting.
 * - **The Staff d'Unité disappearing entirely.**
 * - **The importer losing their own admin access.**
 *
 * And one hard invariant that is not a signal but a refusal: an import
 * that would TAKE AWAY the site's last administrative access is refused
 * with the confirmation word correctly typed.
 *
 * That invariant, and the two access consequences beside it, apply to the
 * scout year access is actually resolved against — the public year
 * `Core\Security\RoleResolver` is called with. Importing a year prepared
 * in advance replaces a roster nobody's role is read from, so it can
 * remove no one's access, and refusing it "for safety" would block the
 * one import that legitimately arrives with an empty roster in front of
 * it. The landmine of a future year holding no chef d'unité is real, but
 * it goes off when somebody switches the public year, not here.
 */
class RosterReplacementGuard
{
    /**
     * The share of a year's active members a file may drop before the
     * import stops to ask.
     *
     * A constant and not a setting, on purpose: a threshold on a
     * configuration page is a threshold somebody lowers the day it gets
     * in the way, which is the day it was doing its job. 35 % sits well
     * above what a real season produces — a Belgian unit's end-of-year
     * turnover runs to a fifth of its roster, an unusually brutal one to
     * a quarter — and well below a filtered or truncated export, which
     * drops half the unit or more.
     *
     * The single-section signal above has no threshold of any kind, and
     * is the one that catches the actual mistake this guard exists for.
     */
    private const MASS_DEACTIVATION_RATIO = 0.35;

    /**
     * Below this many members, a proportion says nothing: a roster of six
     * losing three is 50 % and entirely ordinary. Small rosters are left
     * to the other three signals.
     */
    private const MASS_DEACTIVATION_MIN_ROSTER = 20;

    public function __construct(
        private RosterComparisonRepository $repository,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    /**
     * Confront a parsed CSV with the roster of the scout year it targets.
     *
     * $importedBy is the `user_accounts.id` running the import, used only
     * to say whether that person is about to remove their own access; 0
     * (no account) simply means the consequence is not stated.
     */
    public function assess(ParsedImport $parsed, int $scoutYearId, int $importedBy): RosterReplacementAssessment
    {
        $fileDeskIds = [];
        $fileSectionCodes = [];
        $fileFunctionCodes = [];
        foreach ($parsed->members as $member) {
            $fileDeskIds[$member->deskId] = true;
            foreach ($member->functions as $function) {
                $fileFunctionCodes[] = $function->functionCode;
                if ($function->sectionCode !== null) {
                    $fileSectionCodes[$function->sectionCode] = true;
                }
            }
        }
        $fileSectionCodes = array_keys($fileSectionCodes);
        sort($fileSectionCodes);

        $rosterMemberCount = $this->repository->countActiveMembers($scoutYearId);
        $rosterDeskIds = $this->repository->findActiveDeskIds($scoutYearId);
        $rosterSectionCodes = $this->repository->findActiveSectionCodes($scoutYearId);

        // Only members active BEFORE this import count as deactivated —
        // re-counting the ones a previous import already retired would
        // trip the barrier on every ordinary end-of-season file.
        $deactivated = array_diff_key($rosterDeskIds, $fileDeskIds);
        $deactivatedCount = count($deactivated);

        $sectionCodesGoingInactive = array_values(array_diff($rosterSectionCodes, $fileSectionCodes));
        sort($sectionCodesGoingInactive);

        $roles = $this->repository->findRolesByFunctionCode($fileFunctionCodes);
        $adminDeskIdsAfter = $this->adminDeskIdsAfter($parsed, $roles);

        $adminDeskIdsBefore = $this->repository->findAdminDeskIds($scoutYearId);
        $unitStaffLost = array_values(array_diff($adminDeskIdsBefore, $adminDeskIdsAfter));

        $hasSuperAdmin = $this->repository->hasSuperAdminAccount();
        $yearBearsAccess = $scoutYearId === (int) $this->scoutYearResolver->getCurrentPublicYear()['id'];

        $verdict = $this->decide(
            fileSectionCount: count($fileSectionCodes),
            rosterSectionCount: count($rosterSectionCodes),
            rosterMemberCount: $rosterMemberCount,
            deactivatedCount: $deactivatedCount,
            adminCountBefore: count($adminDeskIdsBefore),
            adminCountAfter: count($adminDeskIdsAfter),
            hasSuperAdmin: $hasSuperAdmin,
            yearBearsAccess: $yearBearsAccess
        );

        return new RosterReplacementAssessment(
            verdict: $verdict,
            fileMemberCount: count($fileDeskIds),
            fileLineCount: $parsed->lineCount,
            fileSectionCodes: $fileSectionCodes,
            rosterMemberCount: $rosterMemberCount,
            rosterSectionCount: count($rosterSectionCodes),
            rosterImportedAt: $this->repository->findLastImportedAt($scoutYearId),
            deactivatedCount: $deactivatedCount,
            sectionCodesGoingInactive: $sectionCodesGoingInactive,
            unitStaffCount: count($adminDeskIdsBefore),
            unitStaffLostCount: count($unitStaffLost),
            adminCountAfter: count($adminDeskIdsAfter),
            importerLosesAdmin: $yearBearsAccess
                && $this->importerLosesAdmin($importedBy, $scoutYearId, $adminDeskIdsBefore, $adminDeskIdsAfter),
            hasSuperAdminAccount: $hasSuperAdmin,
            yearBearsAccess: $yearBearsAccess
        );
    }

    /**
     * The Desk identifiers that would hold a chef d'unité function once
     * the file is applied.
     *
     * A function code this installation has never seen is not in $roles,
     * and `MappingResolver::resolveFunction()` will create it at role
     * 'identified' (SECURITY.md §3) — so an absence reads as the lowest
     * role. Reading it as "unknown, probably fine" is exactly how a file
     * that empties the Staff d'Unité would slip through.
     *
     * @param array<string, string> $roles
     * @return string[]
     */
    private function adminDeskIdsAfter(ParsedImport $parsed, array $roles): array
    {
        $deskIds = [];
        foreach ($parsed->members as $member) {
            foreach ($member->functions as $function) {
                if (($roles[$function->functionCode] ?? 'identified') === 'admin') {
                    $deskIds[$member->deskId] = true;
                    break;
                }
            }
        }

        return array_keys($deskIds);
    }

    /**
     * @param string[] $adminDeskIdsBefore
     * @param string[] $adminDeskIdsAfter
     */
    private function importerLosesAdmin(int $importedBy, int $scoutYearId, array $adminDeskIdsBefore, array $adminDeskIdsAfter): bool
    {
        if ($importedBy === 0 || $this->repository->isSuperAdminAccount($importedBy)) {
            // A super-admin's access does not come from Desk and no
            // import can take it away — saying otherwise would be the
            // one false alarm on this screen.
            return false;
        }

        $ownDeskIds = $this->repository->findDeskIdsForAccount($importedBy, $scoutYearId);
        $keptAdmin = array_intersect($ownDeskIds, $adminDeskIdsAfter);
        $wasAdmin = array_intersect($ownDeskIds, $adminDeskIdsBefore);

        return $wasAdmin !== [] && $keptAdmin === [];
    }

    private function decide(
        int $fileSectionCount,
        int $rosterSectionCount,
        int $rosterMemberCount,
        int $deactivatedCount,
        int $adminCountBefore,
        int $adminCountAfter,
        bool $hasSuperAdmin,
        bool $yearBearsAccess
    ): RosterReplacementVerdict {
        // The invariant comes first because it is the only verdict the
        // confirmation word cannot lift. Two conditions narrow it, and
        // both matter:
        //
        // - an installation with a super-admin account keeps an
        //   administrative access whatever a Desk file says;
        // - an import can only REMOVE the last administrator if there was
        //   one to remove. A site that has none yet — a fresh install
        //   whose very first import is what will create its Staff
        //   d'Unité — must not be refused the import that would fix that,
        //   which is precisely the import it needs.
        if ($yearBearsAccess && $adminCountBefore > 0 && $adminCountAfter === 0 && !$hasSuperAdmin) {
            return RosterReplacementVerdict::NO_ADMIN_LEFT;
        }

        // A year with no roster yet — the first import of a season, or
        // the first of a year prepared in advance — has nothing to be
        // missing from. This is the correct behaviour, and it only
        // follows from the comparison being scoped to one scout year.
        if ($rosterMemberCount === 0) {
            return RosterReplacementVerdict::ALLOWED;
        }

        if ($fileSectionCount === 1 && $rosterSectionCount > 1) {
            return RosterReplacementVerdict::FILTERED_EXPORT;
        }

        if (
            $rosterMemberCount >= self::MASS_DEACTIVATION_MIN_ROSTER
            && $deactivatedCount / $rosterMemberCount >= self::MASS_DEACTIVATION_RATIO
        ) {
            return RosterReplacementVerdict::MASS_DEACTIVATION;
        }

        return RosterReplacementVerdict::ALLOWED;
    }
}
