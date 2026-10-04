<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Badge\Badge;
use Core\Badge\BadgeException;
use Core\Badge\BadgeService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionDocumentService;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Member\UnitStaffSectionService;
use Core\Pdf\PdfCompressor;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\SectionPickerHelper;
use Core\Member\Repository\MemberSectionTotemRepository;
use Core\View\EditableContentService;
use Twig\Environment;

class StaffsController extends AbstractController
{
    /** The key prefix of a section's own text: permanent, not per year (#725). */
    public const SECTION_TEXT_KEY_PREFIX = 'staff_text_';

    public function __construct(
        protected Environment $twig,
        private SectionService $sectionService,
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService,
        private BadgeService $badgeService,
        private UnitStaffSectionService $unitStaffSectionService,
        private SectionDocumentService $sectionDocumentService,
        private SettingService $settingService,
        private SectionStaffAuthorizationService $sectionStaffAuthorizationService,
        private EditableContentService $editableContentService,
        private MemberSectionTotemRepository $sectionTotems
    ) {
    }

    /**
     * GET /chefs/staffs — render the Staffs page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $currentRole = Role::fromString(AuthSession::getRole());
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $currentRole);
        $scoutYearId = $effectiveYear->id;
        $email = AuthSession::getEmail() ?? '';

        // Idempotent: guarantees "Staff d'U" exists even before any Desk
        // import has ever run (mirrors BadgeService::ensureDefaults()).
        $this->unitStaffSectionService->ensureSection();
        $this->badgeService->syncSectionReferentBadges();

        // Get all sections
        $allSections = $this->sectionService->getAllWithBranches();

        // Get linked members for filtering and default selection
        $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);

        // Filter sections based on role. "Staff d'U" (STAFFDU) is a real
        // section like any other — it flows through this same filter and is
        // visible to chiefs/admins (hasAccess(CHIEF) returns all sections).
        $sections = $this->filterSectionsForRole($allSections, $linkedMembers, $currentRole);
        // Resolved (override or branch-derived) color for the picker dots —
        // same single source of truth as every other section picker/list.
        foreach ($sections as &$section) {
            $section['color'] = SectionService::colorForSection($section);
        }
        unset($section);

        // Resolve selected section
        $requestedId = $request->getQuery('section');
        $requestedSectionId = $requestedId !== null && $requestedId !== '' ? (int) $requestedId : null;
        $selectedSectionId = SectionPickerHelper::resolveDefault($requestedSectionId, $linkedMembers, $sections);

        // Two different questions, deliberately kept apart.
        //
        // $isChief is about the ROLE — "may this account act on this page
        // at all". It still governs contact-info visibility and badge
        // assignment exactly as it always did.
        //
        // $canEditSection is about the SECTION — "is this account an
        // animateur of the section currently displayed" (§8.33). It is the
        // one that gates this section's documents: an animateur of the
        // Baladins has no business writing in the Éclaireurs' documents.
        // Collapsing the two into one boolean, as this page did until now,
        // is precisely what let them.
        $isChief = $currentRole->hasAccess(Role::CHIEF);
        $staffedSectionIds = array_map(
            static fn(array $section): int => (int) $section['id'],
            $this->sectionStaffAuthorizationService->getStaffedSections($email, $currentRole->value, $scoutYearId)
        );

        // Get current section details and staff
        $currentSection = null;
        $staff = [];
        $canEditSection = false;

        if ($selectedSectionId !== null) {
            $currentSection = $this->sectionService->getSection($selectedSectionId);

            if ($currentSection !== null) {
                $canEditSection = $isChief && in_array((int) $currentSection['id'], $staffedSectionIds, true);
                $staff = $this->sectionService->getSectionStaff($selectedSectionId, $scoutYearId);

                // For intendants viewing a section they are not linked to: strip contact info
                if (!$isChief) {
                    $linkedSectionCodes = $this->getLinkedSectionCodes($linkedMembers);
                    if (!in_array($currentSection['desk_code'], $linkedSectionCodes, true)) {
                        $staff = $this->stripContactInfo($staff);
                    }
                }
            }
        }

        $availableBadges = $isChief ? $this->badgeService->getActive() : [];
        // "Référent {section}" badges are only meaningful for Staff d'U
        // members — this page's staff list is always exactly the current
        // section's members, so simply hiding them from the picker on any
        // other section's page is sufficient here (module spec: "can only
        // be assigned to Staff d'U members"; BadgeService::toggleAssignment()
        // enforces this server-side too, regardless of this filtering).
        if ($currentSection !== null && $currentSection['desk_code'] !== UnitStaffSectionService::DESK_CODE) {
            $availableBadges = array_values(array_filter(
                $availableBadges,
                fn(Badge $b) => $b->referentSectionId === null
            ));
        }

        // Documents de section (module addendum) — built for any selected
        // section regardless of role (intendants, and animateurs of another
        // section, see it read-only via can_edit_section); the
        // compression-backend re-detection only runs here, not on every
        // request site-wide, since it's a real subprocess spawn.
        $sectionDocumentYears = [];
        if ($currentSection !== null) {
            $sectionDocumentYears = $this->sectionDocumentService->listYearsForStaffsPage(
                $currentSection['id'],
                $scoutYearId
            );
        }
        $compressionBackend = $this->sectionDocumentService->refreshDetectedBackend();

        // The section's own text (#725): one key per section, no year in
        // it, so it stays from one year to the next until somebody edits it.
        $sectionTextKey = $currentSection !== null ? self::SECTION_TEXT_KEY_PREFIX . (int) $currentSection['id'] : null;

        $context = [
            'sections' => $sections,
            'current_section' => $currentSection,
            'section_text_key' => $sectionTextKey,
            'section_text' => $sectionTextKey !== null
                ? (string) $this->editableContentService->get($sectionTextKey, '')
                : '',
            'staff' => $staff,
            'is_chief' => $isChief,
            'can_edit_section' => $canEditSection,
            // An animateur the Desk import left without a single section
            // can edit no documents anywhere, and nothing on screen would
            // otherwise say why. Only shown to a chief+: an intendant's
            // read-only view is the expected state, not a misconfiguration.
            'chief_without_staffed_section' => $isChief && $staffedSectionIds === [],
            'section_document_years' => $sectionDocumentYears,
            'section_document_compression_backend' => $compressionBackend,
            'section_document_compression_backend_none' => $compressionBackend === PdfCompressor::BACKEND_NONE,
            'section_document_oversize_warning_mb' =>
                (int) ($this->settingService->get('section_document_oversize_warning_mb') ?: 5),
            'available_badges' => $availableBadges,
        ];
        // The section picker changes what this page shows without changing
        // its URL structure (?section={id}) — the breadcrumb's own segment
        // must reflect the current selection the same way the page title
        // does (chefs/staffs.html.twig's "current_section.name ?? desk_code").
        if ($currentSection !== null) {
            $context['breadcrumb_current'] = 'Staffs · ' . ($currentSection['name'] ?? $currentSection['desk_code']);
        }

        return $this->render('chefs/staffs.html.twig', $context);
    }

    /**
     * POST /chefs/staffs/text — a section's own rich text (#725), in the
     * body shape rich-text-field.js sends ({key, value, type}).
     *
     * Its own endpoint on purpose: /api/rich-text-content stays admin-only.
     * Here the question is the one the page asks to show the button — does
     * this account animate THIS section (an admin animates every one) — and
     * it is asked again, because a hidden button is not a boundary. The
     * global edit mode plays no part.
     *
     * @param array<string, string> $params
     */
    public function saveSectionText(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $key = (string) ($data['key'] ?? '');
        if (preg_match('/^' . self::SECTION_TEXT_KEY_PREFIX . '([1-9]\d*)$/', $key, $match) !== 1
            || ($data['type'] ?? 'rich_text') !== 'rich_text'
        ) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        $sectionId = (int) $match[1];

        $userId = AuthSession::getUserAccountId();
        if ($userId === null || !$this->mayEditSection($sectionId)) {
            return $this->json(['success' => false, 'error' => "Vous n'animez pas cette section."], 403);
        }

        $stored = $this->editableContentService->set($key, (string) ($data['value'] ?? ''), 'rich_text', $userId);

        $this->journalService->log(
            'core',
            'section_text_updated',
            'info',
            'Texte de section modifié',
            ['section_id' => $sectionId],
            $userId
        );

        return $this->json(['success' => true, 'value' => $stored]);
    }

    /**
     * The page's own rule for every write that follows the section: a chief
     * who animates it this year, or an admin (who animates every section).
     */
    private function mayEditSection(int $sectionId): bool
    {
        $role = Role::fromString(AuthSession::getRole());
        if (!$role->hasAccess(Role::CHIEF) || $this->sectionService->getSection($sectionId) === null) {
            return false;
        }
        $scoutYearId = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $role)->id;
        $staffed = $this->sectionStaffAuthorizationService->getStaffedSections(
            AuthSession::getEmail() ?? '',
            $role->value,
            $scoutYearId
        );

        foreach ($staffed as $section) {
            if ((int) $section['id'] === $sectionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * POST /chefs/staffs/badge-toggle — assign/unassign a badge to a staff
     * member for the current scout year (AJAX, JSON). Chief-only, same gate
     * as updateSection().
     *
     * @param array<string, string> $params
     */
    public function toggleBadge(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $memberYearId = isset($data['member_year_id']) ? (int) $data['member_year_id'] : 0;
        $badgeId = isset($data['badge_id']) ? (int) $data['badge_id'] : 0;
        if ($memberYearId <= 0 || $badgeId <= 0) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $assigned = $this->badgeService->toggleAssignment($memberYearId, $badgeId, AuthSession::getUserAccountId());
        } catch (BadgeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'core',
            $assigned ? 'badge_assigned' : 'badge_unassigned',
            'info',
            $assigned ? 'Badge attribué à un membre' : 'Badge retiré à un membre',
            ['member_year_id' => $memberYearId, 'badge_id' => $badgeId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'assigned' => $assigned]);
    }

    /**
     * POST /chefs/staffs/totem-de-section — the totem a staff member carries
     * in the section shown, this year (« Akela », issue #722).
     *
     * Same people as the badges: the route is `chief`, like
     * /chefs/staffs/badge-toggle, and a chief sees every section on this
     * page. What is checked here is that the pair makes sense — the member
     * really holds a function in that section — so a hand-made request
     * cannot give anybody a totem in a section they are not in. A blank
     * totem removes it.
     *
     * @param array<string, string> $params
     */
    public function saveSectionTotem(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $memberYearId = (int) ($data['member_year_id'] ?? 0);
        $sectionId = (int) ($data['section_id'] ?? 0);
        $totem = trim((string) ($data['totem'] ?? ''));
        if ($memberYearId <= 0 || $sectionId <= 0) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (mb_strlen($totem) > 100) {
            return $this->json(['success' => false, 'error' => 'Ce totem de section est trop long.'], 422);
        }

        $section = $this->sectionService->getSection($sectionId);
        $scoutYearId = $this->scoutYearResolver
            ->getEffectiveYear(ScoutYearSession::getPreviewId(), Role::fromString(AuthSession::getRole()))->id;
        $isStaff = $section !== null
            && $this->sectionService->isStaffOfSection($memberYearId, $sectionId, $scoutYearId);
        if (!$isStaff) {
            return $this->json(
                ['success' => false, 'error' => "Ce membre n'est pas dans le staff de cette section."],
                422
            );
        }

        $this->sectionTotems->set($memberYearId, $sectionId, $totem, AuthSession::getUserAccountId());

        // What changed and for whom, never the totem itself: it is a name.
        $this->journalService->log(
            'core',
            $totem === '' ? 'section_totem_removed' : 'section_totem_set',
            'info',
            $totem === '' ? 'Totem de section retiré' : 'Totem de section enregistré',
            ['member_year_id' => $memberYearId, 'section_id' => $sectionId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * Filter sections based on user role.
     * Intendants see only sections they are linked to.
     * Chiefs/admins see all sections.
     *
     * @param array<int, array<string, mixed>> $allSections
     * @param \Core\Member\MemberProfile[] $linkedMembers
     * @return array<int, array<string, mixed>>
     */
    private function filterSectionsForRole(array $allSections, array $linkedMembers, Role $role): array
    {
        if ($role->hasAccess(Role::CHIEF)) {
            return $allSections;
        }

        // Intendant: filter to linked sections only
        $linkedSectionCodes = $this->getLinkedSectionCodes($linkedMembers);
        return array_values(array_filter(
            $allSections,
            fn(array $s) =>
                in_array($s['desk_code'], $linkedSectionCodes, true)
        ));
    }

    /**
     * Get all section desk_codes from linked members' functions.
     *
     * @param \Core\Member\MemberProfile[] $linkedMembers
     * @return string[]
     */
    private function getLinkedSectionCodes(array $linkedMembers): array
    {
        $codes = [];
        foreach ($linkedMembers as $member) {
            foreach ($member->functions as $fn) {
                if ($fn->sectionCode !== null) {
                    $codes[] = $fn->sectionCode;
                }
            }
        }
        return array_unique($codes);
    }

    /**
     * Strip contact info from staff members (for intendants viewing other sections).
     *
     * @param \Core\Member\MemberProfile[] $staff
     * @return \Core\Member\MemberProfile[]
     */
    private function stripContactInfo(array $staff): array
    {
        $stripped = [];
        foreach ($staff as $member) {
            $stripped[] = new \Core\Member\MemberProfile(
                memberYearId: $member->memberYearId,
                memberId: $member->memberId,
                deskId: $member->deskId,
                firstName: $member->firstName,
                lastName: $member->lastName,
                totem: $member->totem,
                quali: $member->quali,
                gender: null,
                birthDate: null,
                phone: null,
                mobile: null,
                email: null,
                patrol: null,
                formationLevel: $member->formationLevel,
                federationMailConsent: false,
                unitMailConsent: false,
                addresses: [],
                functions: $member->functions,
                scoutYearLabel: $member->scoutYearLabel,
                badges: $member->badges,
                sectionTotems: $member->sectionTotems
            );
        }
        return $stripped;
    }
}
