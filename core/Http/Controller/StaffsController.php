<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\SectionPickerHelper;
use Twig\Environment;

class StaffsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private SectionService $sectionService,
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService
    ) {
    }

    public const UNIT_STAFF_SECTION_ID = -1;

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

        // Get all sections
        $allSections = $this->sectionService->getAllWithBranches();

        // Get linked members for filtering and default selection
        $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);

        // Filter sections based on role
        $sections = $this->filterSectionsForRole($allSections, $linkedMembers, $currentRole);

        // Append virtual "Staff d'U" section for chiefs/admins
        if ($currentRole->hasAccess(Role::CHIEF)) {
            $sections[] = [
                'id' => self::UNIT_STAFF_SECTION_ID,
                'desk_code' => '__staff_u__',
                'name' => "Staff d'U",
                'email' => null,
                'age_branch_id' => 0,
                'branch_name' => "Staff d'unité",
                'branch_sort_order' => 50,
            ];
        }

        // Resolve selected section
        $requestedId = $request->getQuery('section');
        $requestedSectionId = $requestedId !== null && $requestedId !== '' ? (int) $requestedId : null;
        $selectedSectionId = SectionPickerHelper::resolveDefault($requestedSectionId, $linkedMembers, $sections);

        // Get current section details and staff
        $currentSection = null;
        $staff = [];
        $canEditSection = $currentRole->hasAccess(Role::CHIEF);

        if ($selectedSectionId === self::UNIT_STAFF_SECTION_ID) {
            $currentSection = [
                'id' => self::UNIT_STAFF_SECTION_ID,
                'desk_code' => '__staff_u__',
                'name' => "Staff d'U",
                'email' => null,
                'age_branch_id' => 0,
                'branch_name' => "Staff d'unité",
            ];
            $staff = $this->sectionService->getUnitStaff($scoutYearId);
        } elseif ($selectedSectionId !== null) {
            $currentSection = $this->sectionService->getSection($selectedSectionId);

            if ($currentSection !== null) {
                $staff = $this->sectionService->getSectionStaff($selectedSectionId, $scoutYearId);

                // For intendants viewing a section they are not linked to: strip contact info
                if (!$canEditSection) {
                    $linkedSectionCodes = $this->getLinkedSectionCodes($linkedMembers);
                    if (!in_array($currentSection['desk_code'], $linkedSectionCodes, true)) {
                        $staff = $this->stripContactInfo($staff);
                    }
                }
            }
        }

        return $this->render('chefs/staffs.html.twig', [
            'sections' => $sections,
            'current_section' => $currentSection,
            'staff' => $staff,
            'can_edit_section' => $canEditSection,
        ]);
    }

    /**
     * POST /chefs/staffs/update-section — update section name and email (AJAX).
     *
     * @param array<string, string> $params
     */
    public function updateSection(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $sectionId = isset($data['section_id']) ? (int) $data['section_id'] : 0;
        if ($sectionId <= 0) {
            return $this->json(['success' => false, 'error' => 'Identifiant de section manquant.'], 400);
        }

        $oldSection = $this->sectionService->getSection($sectionId);
        if ($oldSection === null) {
            return $this->json(['success' => false, 'error' => 'Section introuvable.'], 400);
        }

        $name = isset($data['name']) ? (string) $data['name'] : null;
        $email = isset($data['email']) ? (string) $data['email'] : null;

        $this->sectionService->updateSectionInfo($sectionId, $name, $email);

        $this->journalService->log(
            'core',
            'section_info_updated',
            'info',
            "Informations de la section {$oldSection['desk_code']} mises à jour",
            [
                'section_id' => $sectionId,
                'old_name' => $oldSection['name'],
                'new_name' => $name,
                'old_email' => $oldSection['email'],
                'new_email' => $email,
            ]
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
        return array_values(array_filter($allSections, fn(array $s) =>
            in_array($s['desk_code'], $linkedSectionCodes, true)));
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
                scoutYearLabel: $member->scoutYearLabel
            );
        }
        return $stripped;
    }
}
