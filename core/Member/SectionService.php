<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

class SectionService
{
    /** "Staff d'U" — outside MemberYearService::BRANCHES' age-branch palette
     *  (it has no age range, so it can't just be added there without
     *  corrupting effective-age computation), so it needs its own entry
     *  here rather than falling through colorForBranchSortOrder()'s
     *  unmapped-index fallback (gray). */
    private const STAFFDU_COLOR = '#7EC8E3';

    /*
     * The lightest of the three blues. Baladins (#1B3F8B) is the darkest and
     * Éclaireurs (#378ADD) sits between them — see
     * MemberYearService::BRANCHES, which is where the other four live.
     */

    public function __construct(
        private SectionRepository $sections,
        private MemberProfileRepository $profiles
    ) {
    }

    /**
     * Single source of truth for "what color represents this section" —
     * every section picker/list across the site (Staffs, trombinoscope,
     * the calendar module, statistics) should call this rather than
     * MemberYearService::colorForBranchSortOrder() directly, so "Staff
     * d'U" gets a real, consistent color everywhere instead of silently
     * falling back to gray. An explicit admin override
     * (sections.color, Configuration > Correspondances Desk) always wins over the
     * branch-derived default.
     *
     * @param array{desk_code: string, branch_sort_order: int, color?: ?string} $section
     */
    public static function colorForSection(array $section): string
    {
        if (!empty($section['color'])) {
            return $section['color'];
        }
        if ($section['desk_code'] === UnitStaffSectionService::DESK_CODE) {
            return self::STAFFDU_COLOR;
        }
        return MemberYearService::colorForBranchSortOrder($section['branch_sort_order']);
    }

    /**
     * Get sections with their branch info, ordered by branch sort_order then
     * desk_code. Inactive sections (is_active = false — no members this
     * scout year, see MappingResolver::deactivateAllSections()) are ALWAYS
     * excluded, everywhere, including the Correspondances Desk admin page: an inactive
     * section is never deleted, but never shown either, until a later import
     * gives it members again. Hidden sections (is_visible = false, the
     * admin's manual toggle) are additionally excluded unless $includeHidden
     * is true — pass true only for the Correspondances Desk page, which needs to see
     * (and manage) hidden-but-active sections.
     *
     * @return array<
     *     int,
     *     array{
     *         id: int,
     *         desk_code: string,
     *         name: ?string,
     *         email: ?string,
     *         age_branch_id: int,
     *         branch_name: string,
     *         branch_sort_order: int,
     *         is_visible: bool,
     *         is_active: bool,
     *         color: ?string
     *     }
     * >
     */
    public function getAllWithBranches(bool $includeHidden = false): array
    {
        return $this->sections->allWithBranches($includeHidden);
    }

    /**
     * Batch lookup by id, WITHOUT the is_active/is_visible filtering
     * getAllWithBranches() applies — a caller resolving a *historical*
     * section reference (e.g. "which section was this member in last
     * year") must still resolve it even if that section has since gone
     * inactive or been hidden; it never disappears from the sections
     * table itself (§8.8: a section with no current members is deactivated,
     * never deleted).
     *
     * @param int[] $sectionIds
     * @return array<
     *     int,
     *     array{
     *         id: int,
     *         desk_code: string,
     *         name: ?string,
     *         email: ?string,
     *         age_branch_id: int,
     *         branch_name: string,
     *         branch_sort_order: int,
     *         color: ?string
     *     }
     * > keyed by id
     */
    public function findByIds(array $sectionIds): array
    {
        return $this->sections->findByIds($sectionIds);
    }

    /**
     * Whether $memberYearId has a function in the section identified by
     * $sectionDeskCode — used by Core\Badge\BadgeService to restrict
     * "Référent {section}" badges to Staff d'U members (UnitStaffSectionService
     * ::DESK_CODE), regardless of which section's Staffs page the
     * assignment was triggered from.
     */
    public function isMemberYearInSection(int $memberYearId, string $sectionDeskCode): bool
    {
        return $this->sections->hasFunctionInSection($memberYearId, $sectionDeskCode);
    }

    /**
     * Get a single section by ID with branch info.
     *
     * @return array{
     *     id: int,
     *     desk_code: string,
     *     name: ?string,
     *     email: ?string,
     *     age_branch_id: int,
     *     branch_name: string,
     *     branch_sort_order: int,
     *     color: ?string
     * }|null
     */
    public function getSection(int $sectionId): ?array
    {
        return $this->sections->findById($sectionId);
    }

    /**
     * Resolve a section (with branch info) from its Desk code — the only
     * identifier MemberFunctionInfo carries (sectionCode, not a numeric
     * id). Used where a caller only has a MemberProfile's function list to
     * start from (e.g. the member page resolving "this member's own
     * section" from their main function).
     *
     * @return array{
     *     id: int,
     *     desk_code: string,
     *     name: ?string,
     *     email: ?string,
     *     age_branch_id: int,
     *     branch_name: string,
     *     branch_sort_order: int,
     *     color: ?string
     * }|null
     */
    public function findByDeskCode(string $deskCode): ?array
    {
        return $this->sections->findByDeskCode($deskCode);
    }

    /**
     * Get the staff (chiefs / chef d'unité) for a section in the current
     * scout year. Returns decrypted MemberProfile objects for members whose
     * function is linked to this section AND whose role is chief or admin —
     * a section's animés also carry a section_id on their member_functions
     * row, so this must filter by role to show staff only.
     *
     * @return MemberProfile[]
     */
    public function getSectionStaff(int $sectionId, int $scoutYearId): array
    {
        $memberYearIds = $this->sections->memberYearIdsInSection($sectionId, $scoutYearId, staff: true);

        if (count($memberYearIds) === 0) {
            return [];
        }

        $profiles = array_values($this->hydrateMemberProfiles($memberYearIds));

        // Sort by display name
        usort(
            $profiles,
            fn(MemberProfile $a, MemberProfile $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName())
        );

        return $profiles;
    }

    /**
     * The animés (non-staff members) of a section for a scout year — the
     * exact complement of getSectionStaff() above, same trap it already
     * guards against: an animé's own member_functions row carries the
     * SAME section_id as that section's staff, so only the role filter
     * (here, everyone NOT chief/admin/intendant) tells them apart. First
     * consumer: the registration module's "Départs" and "Passage" pages
     * (ARCHITECTURE.md §8.36), which list children, never leaders — an
     * Intendant function was originally missing from this exclusion list
     * (only chief/admin were), so a member tagged Intendant showed up
     * counted as an animé on those pages; the exact same fix is mirrored
     * in Modules\Registration\Service\PassageService::getAnimeMemberYears()
     * and ForecastService::countDeparturesForYear(), which build their own
     * copy of this same role filter rather than calling this method.
     *
     * @return MemberProfile[]
     */
    public function getSectionAnimes(int $sectionId, int $scoutYearId): array
    {
        $memberYearIds = $this->getSectionAnimeMemberYearIds($sectionId, $scoutYearId);

        if (count($memberYearIds) === 0) {
            return [];
        }

        $profiles = array_values($this->hydrateMemberProfiles($memberYearIds));

        usort(
            $profiles,
            fn(MemberProfile $a, MemberProfile $b) => strcasecmp($a->getDisplayName(), $b->getDisplayName())
        );

        return $profiles;
    }

    /**
     * getSectionStaff() for many sections in one pass — one query for the
     * member years, one hydration for everybody — for the callers that
     * loop over every section of the unit (the groups module's invite
     * picker, the camps module's « réservé par » list).
     *
     * @param array<int, int> $sectionIds
     * @return array<int, MemberProfile[]> keyed by section id, every requested
     *     id present, each list sorted by display name
     */
    public function getStaffForSections(array $sectionIds, int $scoutYearId): array
    {
        return $this->profilesBySection($sectionIds, $scoutYearId, staff: true);
    }

    /**
     * getSectionAnimes() for many sections in one pass.
     *
     * @param array<int, int> $sectionIds
     * @return array<int, MemberProfile[]> keyed by section id, every requested
     *     id present, each list sorted by display name
     */
    public function getAnimesForSections(array $sectionIds, int $scoutYearId): array
    {
        return $this->profilesBySection($sectionIds, $scoutYearId, staff: false);
    }

    /**
     * @param array<int, int> $sectionIds
     * @return array<int, MemberProfile[]>
     */
    private function profilesBySection(array $sectionIds, int $scoutYearId, bool $staff): array
    {
        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        $bySection = array_fill_keys($sectionIds, []);
        if ($sectionIds === []) {
            return $bySection;
        }

        $rows = $this->sections->memberYearIdsInSections($sectionIds, $scoutYearId, $staff);

        $profiles = $this->hydrateMemberProfiles(array_column($rows, 'member_year_id'));
        foreach ($rows as $row) {
            $profile = $profiles[$row['member_year_id']] ?? null;
            if ($profile !== null) {
                $bySection[$row['section_id']][] = $profile;
            }
        }
        foreach ($bySection as &$list) {
            usort(
                $list,
                static fn(MemberProfile $a, MemberProfile $b): int
                    => strcasecmp($a->getDisplayName(), $b->getDisplayName())
            );
        }
        unset($list);

        return $bySection;
    }

    /**
     * The member_year ids behind getSectionAnimes() — THE definition of
     * "animé of this section" (see getSectionAnimes()'s own doc comment
     * for the role-filter history), selectable without paying the full
     * profile hydration. For callers that only need membership — the
     * Départs write path re-checks "is this member_year one of my animés"
     * on every field save, and hydrating every animé of every staffed
     * section to answer it cost hundreds of queries per keystroke.
     *
     * @return int[]
     */
    public function getSectionAnimeMemberYearIds(int $sectionId, int $scoutYearId): array
    {
        return $this->sections->memberYearIdsInSection($sectionId, $scoutYearId, staff: false);
    }

    /**
     * The sections one member year ANIMATES — the exact complement of
     * getSectionAnimeMemberYearIds() above, asked from the person's side.
     *
     * « Animates » is `member_functions` of role chief/admin on a real
     * section, the same resolution SectionStaffAuthorizationService and
     * Modules\Finance\Service\TreasurerScopeService use. An
     * `intendant` function does not animate: the role exists to reach the
     * finances without being a chief, and it carries no section staff.
     *
     * First consumer: Core\Badge\BadgeService, which refuses the
     * Trésorier badge to somebody it could not possibly help — the badge
     * only ever grants the account of a section its holder animates
     * (issue #222).
     *
     * @return int[]
     */
    public function getAnimatedSectionIds(int $memberYearId): array
    {
        return $this->sections->sectionIdsAnimatedBy($memberYearId);
    }

    /**
     * getSectionAnimeMemberYearIds()'s twin, in the PERSISTENT identity:
     * the `members.id` of a section's animés for one scout year, from the
     * same single definition of « animé of this section » and the same
     * single query.
     *
     * Same reason as its sibling, applied to a table keyed on
     * `members.id` rather than on `member_years.id` — `presences_records`
     * is the first one (ARCHITECTURE.md §4: an attendance record must
     * survive the September that saw it written). The attendance sheet
     * re-checks « is this animé one of THIS section's » on every tap, and
     * answering that through getSectionAnimes() would hydrate and decrypt
     * every animé of the section for each of twenty-five names.
     *
     * @return int[]
     */
    public function getSectionAnimeMemberIds(int $sectionId, int $scoutYearId): array
    {
        return $this->sections->memberIdsOfAnimes($sectionId, $scoutYearId);
    }

    /**
     * Update a section's configurable info (name, email).
     */
    public function updateSectionInfo(int $sectionId, ?string $name, ?string $email): void
    {
        $cleanName = $name !== null && trim($name) !== '' ? trim($name) : null;
        $cleanEmail = $email !== null && trim($email) !== '' ? trim($email) : null;

        $this->sections->updateInfo($sectionId, $cleanName, $cleanEmail);
    }

    /**
     * Show or hide a section from every section picker across the site.
     */
    public function updateSectionVisibility(int $sectionId, bool $visible): void
    {
        $this->sections->updateVisibility($sectionId, $visible);
    }

    /**
     * Set (or clear, when $color is null/empty) the section's explicit
     * color override — see colorForSection()'s doc comment. Validated as a
     * "#RRGGBB" hex string so every consumer of colorForSection() can trust
     * the value is safe to drop straight into a CSS background-color.
     *
     * @throws SectionException on an invalid hex color
     */
    public function updateSectionColor(int $sectionId, ?string $color): void
    {
        $clean = $color !== null && trim($color) !== '' ? trim($color) : null;
        if ($clean !== null && !preg_match('/^#[0-9A-Fa-f]{6}$/', $clean)) {
            throw new SectionException('Couleur invalide — format hexadécimal attendu (ex : #378ADD).');
        }

        $this->sections->updateColor($sectionId, $clean);
    }

    /**
     * Hydrate a MemberProfile from a member_year ID. Public so other core
     * services/modules that already have a filtered list of member_year ids
     * (e.g. the trombinoscope module) can reuse this decryption/hydration
     * logic instead of duplicating it. A single-id wrapper around the batch
     * below, so there is exactly one definition of what a hydrated profile
     * contains.
     */
    public function hydrateMemberProfile(int $memberYearId): ?MemberProfile
    {
        return $this->hydrateMemberProfiles([$memberYearId])[$memberYearId] ?? null;
    }

    /**
     * Batch counterpart of hydrateMemberProfile(): the same four lookups
     * (member rows, functions, scout-year labels, badges), each issued
     * once for the whole set instead of once per member. A roster page
     * hydrating 60 people used to cost ~240 queries; this costs 4.
     *
     * @param int[] $memberYearIds
     * @return array<int, MemberProfile> keyed by member_year id — an id
     *         that resolves to no row is simply absent, exactly as the
     *         single variant returns null for it.
     */
    public function hydrateMemberProfiles(array $memberYearIds): array
    {
        return $this->profiles->hydrateMany($memberYearIds);
    }
}
