<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Badge\Badge;
use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Import\AgeBranchRepository;
use Core\Module\FormationPathProvider;
use Core\Module\MemberPaymentProvider;
use Core\Module\SectionResponsableProvider;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Gallery\Api\GalleryAlbumProvider;
use Modules\MassMail\Api\MassMailQueryInterface;

/**
 * Orchestrates every data need of the member page (core/View/templates/
 * members/show.html.twig) — the "Espace membres" page. Kept out of
 * Core\Http\Controller\MemberController so that controller stays a thin
 * orchestrator (AGENTS.md: no business logic in controllers). Every
 * optional-module dependency here is nullable, injected only when that
 * module is enabled (ARCHITECTURE.md §7.5) — each corresponding block in
 * buildPageData()'s return degrades to "not displayed" (an empty
 * array/null), never an error, when its dependency is absent.
 */
class MemberPageService
{
    public function __construct(
        private SectionService $sectionService,
        private MemberService $memberService,
        private BadgeRepository $badgeRepository,
        private MemberBadgeRepository $memberBadgeRepository,
        private AgeBranchRepository $ageBranchRepository,
        private MemberDocumentService $memberDocumentService,
        private MemberEmailService $memberEmailService,
        private SectionDocumentService $sectionDocumentService,
        private ?SectionResponsableProvider $sectionResponsableProvider = null,
        private ?MassMailQueryInterface $massMailQuery = null,
        private ?GalleryAlbumProvider $galleryAlbumProvider = null,
        private ?CalendarEventLookupInterface $calendarEventLookup = null,
        private ?FormationPathProvider $formationPathProvider = null,
        private ?MemberPaymentProvider $memberPaymentProvider = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPageData(MemberProfile $profile, int $scoutYearId, bool $isSelf, bool $isChiefOrAbove, Role $viewerRole): array
    {
        // §3-§8 personal-data blocks: visible to the member themselves or
        // to a chief/admin (same rule the page already applies to
        // contact/address info) — except member_documents (§5), which is
        // deliberately self-only, no staff bypass (see
        // Core\File\FileAccessGuard's own no-bypass rule for owner-scoped
        // files, which is what actually enforces the download boundary;
        // this flag only controls whether the list itself is shown).
        $showPersonal = $isSelf || $isChiefOrAbove;

        $section = $this->resolveOwnSection($profile);

        // "Adresses email" — self-service only (no chief/admin UI in this
        // iteration), same self-only rule as member_documents.
        // $resendCooldownMinutes is a display-only aid (keyed by
        // MemberEmail::$id) — the template disables "Renvoyer" and shows
        // the wait time for a still-cooling-down pending row; the real
        // enforcement is server-side, in MemberEmailService::
        // resendConfirmation() itself.
        $memberEmails = $isSelf ? $this->memberEmailService->listForMember($profile->memberId, $profile->email) : [];
        $resendCooldownMinutes = [];
        foreach ($memberEmails as $memberEmail) {
            if ($memberEmail->isPending()) {
                $resendCooldownMinutes[$memberEmail->id] = $this->memberEmailService->resendCooldownRemainingMinutes($memberEmail);
            }
        }

        return [
            'branch_card' => $section !== null ? $this->buildBranchCard($section) : null,
            'section_info' => $section !== null ? $this->buildSectionInfo($profile, $section, $scoutYearId) : null,
            'recent_mass_mail_emails' => $showPersonal ? $this->getRecentMassMailEmails($profile) : [],
            'member_documents' => $isSelf ? $this->memberDocumentService->listForMember($profile->memberId, $scoutYearId) : [],
            // §7bis — "Documents de section": every past/present section the
            // member was active in that has staff-uploaded documents, most
            // recent year first. Not self-only, unlike member_documents
            // above — these are staff-shared content, not private uploads,
            // so a chief/admin viewing the page sees the same thing.
            'member_section_documents' => $showPersonal ? $this->sectionDocumentService->listForMemberPage($profile->memberId) : [],
            'member_emails' => $memberEmails,
            'member_email_resend_cooldown_minutes' => $resendCooldownMinutes,
            'gallery_albums' => $this->getGalleryAlbums($profile),
            'trombinoscope_enabled' => $this->sectionResponsableProvider !== null,
            'calendar_enabled' => $this->calendarEventLookup !== null,
            // Distinct from the (possibly empty) lists above: the template
            // needs to tell "module enabled, nothing to show yet" (render
            // the card with an empty-state message) apart from "module
            // disabled" (don't render the card at all) — an empty array
            // alone can't distinguish the two.
            'mass_mail_enabled' => $this->massMailQuery !== null,
            'gallery_enabled' => $this->galleryAlbumProvider !== null,
            // §6bis — the member's own training path (leadership module).
            // Resolved only for the member themselves, here and not merely
            // in the template: $showPersonal would have handed a chief the
            // data and left the hiding to a Twig condition, and a block
            // that is never built cannot leak through a later template
            // edit. Same posture as member_documents above.
            // What this member still owes — the amount, the
            // communication and the QR to pay it with. Under
            // $showPersonal rather than $isSelf: a treasurer looking at
            // the page is exactly who gets asked "où en est-elle ?" at
            // the section's door, and the same block answers it. Who may
            // be on this page at all was decided by
            // Core\Http\Controller\MemberController::show().
            'open_payments' => $showPersonal
                ? ($this->memberPaymentProvider?->getOpenPayments($profile->memberId) ?? [])
                : [],
            'formation_path' => $isSelf
                ? $this->formationPathProvider?->getFormationPath($profile->memberId, $scoutYearId)
                : null,
        ];
    }

    /**
     * The member's own section, resolved from their main function's
     * section code (MemberFunctionInfo carries only the Desk code, not a
     * numeric id — see SectionService::findByDeskCode()). Null when the
     * member has no section-linked function at all (e.g. a still-
     * unconfirmed function with no section).
     *
     * @return array{id: int, desk_code: string, name: ?string, email: ?string, age_branch_id: int, branch_name: string, branch_sort_order: int, color: ?string}|null
     */
    private function resolveOwnSection(MemberProfile $profile): ?array
    {
        $sectionCode = $profile->getMainFunction()?->sectionCode;
        if ($sectionCode === null) {
            return null;
        }

        return $this->sectionService->findByDeskCode($sectionCode);
    }

    /**
     * §2 — branch card: federation logo (uploaded, else shipped default,
     * else nothing — never an empty box) + explanation link. Always
     * rendered when a section resolves, even with no logo at all (the
     * link alone is still meaningful — see AgeBranchRepository::
     * defaultLogoFilename()'s docblock for why Staff d'U has no default).
     *
     * @param array{age_branch_id: int, branch_name: string, branch_sort_order: int} $section
     * @return array{label: string, logo_file_id: ?int, default_logo: ?string, explanation_url: string}|null
     */
    private function buildBranchCard(array $section): ?array
    {
        $branch = $this->ageBranchRepository->findById($section['age_branch_id']);
        if ($branch === null) {
            return null;
        }

        return [
            'label' => $branch['label'],
            'logo_file_id' => $branch['logo_file_id'],
            'default_logo' => AgeBranchRepository::defaultLogoFilename($branch['sort_order']),
            'explanation_url' => $branch['explanation_url'],
        ];
    }

    /**
     * §3 — "Informations essentielles": section name/email, responsable
     * (full postal address, plus name — rendered by the template via the
     * display_name_full filter, "Totem (Prénom Nom)"/"Prénom Nom"),
     * badges assigned within the section (name + holder MemberProfile(s),
     * same display_name_full treatment), Staff d'U "Référent {section}"
     * badge holders, next upcoming section event, and the member's own
     * functions this year.
     *
     * @param array{id: int, name: ?string, email: ?string} $section
     * @return array{section: array<string, mixed>, responsable: ?MemberProfile, badge_assignments: array<string, MemberProfile[]>, referent_holders: MemberProfile[], next_event: mixed, functions_this_year: mixed}
     */
    private function buildSectionInfo(MemberProfile $profile, array $section, int $scoutYearId): array
    {
        $responsable = null;
        if ($this->sectionResponsableProvider !== null) {
            $lead = $this->sectionResponsableProvider->getResponsable($section['id'], $scoutYearId);
            if ($lead !== null) {
                // SectionService::hydrateMemberProfile() (which every
                // SectionResponsableProvider implementation is built on)
                // never loads addresses — re-resolve via the one method
                // that does, specifically for this postal-address need.
                $responsable = $this->memberService->findProfileByMemberAndYear($lead->memberId, $scoutYearId) ?? $lead;
            }
        }

        $sectionStaff = $this->sectionService->getSectionStaff($section['id'], $scoutYearId);
        $badgeAssignments = [];
        foreach ($sectionStaff as $staffMember) {
            foreach ($staffMember->badges as $badge) {
                /** @var Badge $badge */
                if ($badge->referentSectionId !== null) {
                    // Référent badges are surfaced separately below (they
                    // belong to a Staff d'U holder, not this section's own
                    // staff list) — skip here to avoid double-listing.
                    continue;
                }
                // Full MemberProfile (not a pre-extracted display name) so
                // the template can format it as "Totem (Prénom Nom)" via
                // the display_name_full filter.
                $badgeAssignments[$badge->name][] = $staffMember;
            }
        }

        $referentHolders = [];
        $referentBadge = $this->badgeRepository->findByReferentSectionId($section['id']);
        if ($referentBadge !== null) {
            $holderIds = $this->memberBadgeRepository->findMemberYearIdsForBadgeAndYear($referentBadge->id, $scoutYearId);
            foreach ($holderIds as $memberYearId) {
                $holder = $this->sectionService->hydrateMemberProfile($memberYearId);
                if ($holder !== null) {
                    $referentHolders[] = $holder;
                }
            }
        }

        $nextEvent = null;
        if ($this->calendarEventLookup !== null) {
            $events = $this->calendarEventLookup->findEventsInWindow(
                new \DateTimeImmutable('today'),
                new \DateTimeImmutable('+1 year'),
                $section['id'],
                Role::PUBLIC
            );
            $nextEvent = $events[0] ?? null;
        }

        return [
            'section' => $section,
            'responsable' => $responsable,
            'badge_assignments' => $badgeAssignments,
            'referent_holders' => $referentHolders,
            'next_event' => $nextEvent,
            'functions_this_year' => $profile->functions,
        ];
    }

    /**
     * §4 — "Communications récentes": only when mass_mail is enabled.
     *
     * @return array<int, array{id: int, subject: string, sent_at: string, section_name: string}>
     */
    private function getRecentMassMailEmails(MemberProfile $profile): array
    {
        return $this->massMailQuery !== null
            ? $this->massMailQuery->getRecentEmailsForMember($profile->memberId, 10)
            : [];
    }

    /**
     * §6 — galleries linked to any section the member belonged to during
     * this scout year (all functions, not just the main one — a member
     * with a secondary function in another section should still see that
     * section's galleries).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getGalleryAlbums(MemberProfile $profile): array
    {
        if ($this->galleryAlbumProvider === null) {
            return [];
        }

        return $this->galleryAlbumProvider->getAlbumsForMember(
            array_values(array_filter(array_map(fn($f) => $f->sectionCode, $profile->functions))),
            $profile->scoutYearLabel,
            6
        );
    }
}
