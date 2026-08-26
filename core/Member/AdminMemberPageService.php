<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Badge\Badge;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Photo\MemberPhotoService;

/**
 * Every data need of the admin member page (`/admin/members/{id}`,
 * core/View/templates/admin/members/show.html.twig) — the consolidated
 * view of what the site knows about one person, for the Staff d'Unité.
 *
 * **A sibling of Core\Member\MemberPageService, deliberately not an
 * extension of it.** That service builds "Espace membres", whose whole
 * shape is decided by two questions this page does not ask: is the
 * viewer the member themselves, and is the viewer a chief. Half of its
 * blocks are self-only with no staff bypass — private documents, the
 * formation path, the email management UI — and inheriting it would
 * import those assumptions into a page whose every reader is a chef
 * d'unité and none of whom is the member. The two consume the same
 * module interfaces (ARCHITECTURE.md §7.5), never each other.
 *
 * The nullable-dependency pattern is the one §8.22 established: a block
 * whose provider is absent is simply not built, never an error and never
 * an empty card.
 *
 * **Two things this page must never show**, and the reasons are worth
 * keeping next to the code:
 *
 * - **A member's private documents.** `files.owner_member_id` carries an
 *   explicit guarantee (ARCHITECTURE.md §8.3): *no chief or admin
 *   bypass*, a file scoped to its owner stays unreachable to staff who
 *   are not that member. Tax certificates will live there. Listing them
 *   here would revoke that guarantee in silence, without anyone
 *   re-reading §8.3.
 * - **A writable secondary-email control.** Those addresses are strict
 *   self-service (§8.27): the member alone manages them, with no chief
 *   or admin bypass. Showing them is defensible — a chef d'unité
 *   fielding "she isn't getting our mails" needs to see which addresses
 *   exist. Making them editable is not, so this service exposes them as
 *   a read-only list and offers no mutation path at all.
 */
class AdminMemberPageService
{
    /**
     * A member accumulates one section period per year, sometimes two —
     * ten years in the unit is a short list, and the page shows it whole
     * rather than paginating a dozen rows.
     */
    private const SECTION_HISTORY_LIMIT = 40;

    public function __construct(
        private MemberBadgeRepository $memberBadgeRepository,
        private MemberPhotoService $memberPhotoService,
        private SectionMembershipRepository $sectionMembershipRepository,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService,
        private MemberEmailRepository $memberEmailRepository
    ) {
    }

    /**
     * @return array{
     *   photo_file_id: ?int,
     *   badges: Badge[],
     *   functions: array<int, array{label: string, section: ?string, is_main: bool}>,
     *   section_history: array<int, array{scout_year_label: string, section_name: string, is_current: bool}>,
     *   member_emails: array<int, array{address: string, status: string}>
     * }
     */
    public function buildPageData(MemberProfile $profile, int $scoutYearId): array
    {
        return [
            'photo_file_id' => $this->memberPhotoService->resolveFileId($profile->memberId, $scoutYearId),
            'badges' => $this->memberBadgeRepository->getActiveBadgesForMemberYear($profile->memberYearId),
            'functions' => $this->buildFunctions($profile),
            'section_history' => $this->buildSectionHistory($profile->memberId, $scoutYearId),
            'member_emails' => $this->buildReadOnlyEmails($profile),
        ];
    }

    /**
     * The year's functions, main one first — the same order
     * MemberProfile itself carries, kept rather than re-sorted so the
     * page and the Desk data agree on which one is "the" function.
     *
     * @return array<int, array{label: string, section: ?string, is_main: bool}>
     */
    private function buildFunctions(MemberProfile $profile): array
    {
        $functions = [];
        foreach ($profile->functions as $function) {
            $functions[] = [
                'label' => $function->functionLabel,
                'section' => $function->sectionName ?? $function->sectionCode,
                'is_main' => $function->isMainFunction,
            ];
        }

        return $functions;
    }

    /**
     * Where this person has been in the unit, most recent first.
     *
     * Read from `member_section_periods`, which is keyed on
     * `members.id` — the persistent identity — so the history survives
     * every scout year the member has lived through, which is the whole
     * point of showing it. A section or a year that no longer resolves
     * is skipped rather than rendered as a blank row.
     *
     * @return array<int, array{scout_year_label: string, section_name: string, is_current: bool}>
     */
    private function buildSectionHistory(int $memberId, int $currentScoutYearId): array
    {
        $history = [];
        $seen = [];
        foreach ($this->sectionMembershipRepository->findAllForMember($memberId) as $period) {
            $key = $period->scoutYearId . ':' . $period->sectionId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $section = $this->sectionService->getSection($period->sectionId);
            $scoutYear = $this->scoutYearService->findById($period->scoutYearId);
            if ($section === null || $scoutYear === null) {
                continue;
            }

            $history[] = [
                'scout_year_label' => (string) $scoutYear['label'],
                'section_name' => (string) ($section['name'] ?? $section['desk_code']),
                'is_current' => $period->scoutYearId === $currentScoutYearId,
            ];

            if (count($history) >= self::SECTION_HISTORY_LIMIT) {
                break;
            }
        }

        return $history;
    }

    /**
     * The member's secondary addresses, as data only.
     *
     * Read-only here on purpose (§8.27 — see this class's own docblock):
     * the returned rows carry an address and a state, and nothing this
     * page could act on. There is no add, no delete and no reactivate,
     * because there is no server-side endpoint that would honour them
     * for anyone but the member.
     *
     * @return array<int, array{address: string, status: string}>
     */
    private function buildReadOnlyEmails(MemberProfile $profile): array
    {
        $rows = [];
        foreach ($this->memberEmailRepository->findByMember($profile->memberId) as $memberEmail) {
            // Manual rows only. The Desk address has its own line in the
            // Desk half of the page — listing it again under « Adresses
            // secondaires » would say the member added an address they
            // never touched.
            if ($memberEmail->source !== MemberEmail::SOURCE_MANUAL) {
                continue;
            }
            $rows[] = [
                'address' => $memberEmail->email,
                'status' => $memberEmail->status,
            ];
        }

        return $rows;
    }
}
