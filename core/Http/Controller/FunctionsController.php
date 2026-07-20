<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Import\FunctionRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Module\FunctionFlagsProvider;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Twig\Environment;

class FunctionsController extends AbstractController
{
    /** @var array<int, array{value: string, label: string, badge_class: string}> */
    private const ROLE_DEFINITIONS = [
        ['value' => 'public', 'label' => 'Public', 'badge_class' => 'bg-secondary-subtle text-secondary-emphasis'],
        ['value' => 'identified', 'label' => 'Animé', 'badge_class' => 'bg-info-subtle text-info-emphasis'],
        ['value' => 'intendant', 'label' => 'Intendant', 'badge_class' => 'bg-primary-subtle text-primary-emphasis'],
        ['value' => 'chief', 'label' => 'Chef', 'badge_class' => 'bg-success-subtle text-success-emphasis'],
        ['value' => 'admin', 'label' => 'Chef d\'Unité', 'badge_class' => 'bg-danger-subtle text-danger-emphasis'],
    ];

    public function __construct(
        protected Environment $twig,
        private FunctionRepository $functionRepo,
        private JournalService $journalService,
        private SectionService $sectionService,
        private UnitStaffSectionService $unitStaffSectionService,
        private ScoutYearResolver $scoutYearResolver,
        private ?FunctionFlagsProvider $functionFlagsProvider = null
    ) {
    }

    /**
     * GET /config/functions — render the "Config Desk" page (function → role
     * mapping, plus section name/visibility).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $unconfirmed = $this->functionRepo->findUnconfirmed();
        $groupedByRole = $this->functionRepo->findAllGroupedByRole();

        // Build confirmed groups with labels and badge classes
        $confirmedByRole = [];
        foreach (self::ROLE_DEFINITIONS as $roleDef) {
            $roleValue = $roleDef['value'];
            if (isset($groupedByRole[$roleValue])) {
                $confirmedByRole[$roleValue] = [
                    'label' => $roleDef['label'],
                    'badge_class' => $roleDef['badge_class'],
                    'functions' => $groupedByRole[$roleValue],
                ];
            }
        }

        // Optional module hook: per-function flag (e.g. trombinoscope
        // "responsable"), only shown for chief/chief-d'unité functions where
        // such a flag is meaningful.
        $functionFlags = null;
        if ($this->functionFlagsProvider !== null) {
            $functionFlags = [
                'section_label' => $this->functionFlagsProvider->getSectionLabel(),
                'lead_label' => $this->functionFlagsProvider->getLeadLabel(),
                'flags' => $this->functionFlagsProvider->getLeadFlags(),
            ];
        }

        // Sections grouped by branch, including hidden ones — this is the
        // only page that manages visibility and color, so it needs to see
        // everything. effective_color is what every picker/list across the
        // site actually renders (explicit override, or the branch-derived
        // default) — the color input is pre-filled with it either way.
        $sectionGroups = [];
        foreach ($this->sectionService->getAllWithBranches(includeHidden: true) as $section) {
            $section['effective_color'] = SectionService::colorForSection($section);
            $branchName = $section['branch_name'];
            if (!isset($sectionGroups[$branchName])) {
                $sectionGroups[$branchName] = ['branch_name' => $branchName, 'sections' => []];
            }
            $sectionGroups[$branchName]['sections'][] = $section;
        }

        return $this->render('config/functions.html.twig', [
            'unconfirmed' => $unconfirmed,
            'confirmed_by_role' => $confirmedByRole,
            'roles' => self::ROLE_DEFINITIONS,
            'function_flags' => $functionFlags,
            'section_groups' => array_values($sectionGroups),
        ]);
    }

    /**
     * POST /config/functions/update — update a function's role (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        // CSRF validation
        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $functionId = isset($json['function_id']) ? (int) $json['function_id'] : 0;
        $newRole = (string) ($json['role'] ?? '');

        // Validate role is one of the assignable roles. The top administrator
        // role (superadmin) is deliberately NOT assignable via Desk functions —
        // it is granted only to the site owner account (is_super_admin).
        $assignableRoles = array_column(self::ROLE_DEFINITIONS, 'value');
        if (!in_array($newRole, $assignableRoles, true)) {
            return $this->json(['success' => false, 'error' => 'Rôle invalide.']);
        }

        // Validate function exists
        $function = $this->functionRepo->findById($functionId);
        if ($function === null) {
            return $this->json(['success' => false, 'error' => 'Fonction introuvable.']);
        }

        // No-op: same role and already confirmed
        if ($function['role'] === $newRole && $function['confirmed']) {
            return $this->json(['success' => true]);
        }

        $oldRole = $function['role'];
        $wasConfirmed = $function['confirmed'];

        // Update role and set confirmed=true
        $this->functionRepo->updateRole($functionId, $newRole, true);

        // A role change may move members into or out of "Staff d'U" — role
        // (unlike the raw Desk import) is only known once confirmed here.
        if ($oldRole !== $newRole) {
            $currentRole = Role::fromString(AuthSession::getRole());
            $effectiveYear = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $currentRole);
            $this->unitStaffSectionService->syncMembership($effectiveYear->id);
        }

        // Log the change (role change or confirmation)
        $description = $wasConfirmed
            ? "Rôle de la fonction {$function['desk_code']} modifié de {$oldRole} à {$newRole}"
            : "Fonction {$function['desk_code']} confirmée avec le rôle {$newRole}";

        if ($oldRole !== $newRole || !$wasConfirmed) {
            $this->journalService->log(
                'core',
                'function_role_changed',
                'security',
                $description,
                [
                    'function_id' => $functionId,
                    'desk_code' => $function['desk_code'],
                    'old_role' => $oldRole,
                    'new_role' => $newRole,
                    'confirmed' => true,
                ],
                AuthSession::getUserAccountId()
            );
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/functions/flags — update a module-provided per-function
     * flag (e.g. trombinoscope "responsable"). No-op (404 behaviour handled
     * by the caller) when no provider is wired.
     *
     * @param array<string, string> $params
     */
    public function updateFlags(Request $request, array $params): Response
    {
        if ($this->functionFlagsProvider === null) {
            return $this->json(['success' => false, 'error' => 'Fonctionnalité indisponible.'], 404);
        }

        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $functionId = isset($json['function_id']) ? (int) $json['function_id'] : 0;
        $function = $this->functionRepo->findById($functionId);
        if ($function === null) {
            return $this->json(['success' => false, 'error' => 'Fonction introuvable.']);
        }

        $lead = (bool) ($json['lead'] ?? false);

        $this->functionFlagsProvider->setLead($functionId, $lead);

        $this->journalService->log(
            'core',
            'function_flags_changed',
            'info',
            "Indicateur de la fonction {$function['desk_code']} modifié",
            ['function_id' => $functionId, 'lead' => $lead],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/functions/section-name — rename a section (AJAX, JSON).
     * Leaves the section's email untouched (not editable from this page).
     *
     * @param array<string, string> $params
     */
    public function updateSectionName(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $sectionId = isset($json['section_id']) ? (int) $json['section_id'] : 0;
        $section = $this->sectionService->getSection($sectionId);
        if ($section === null) {
            return $this->json(['success' => false, 'error' => 'Section introuvable.']);
        }

        $name = isset($json['name']) ? (string) $json['name'] : null;
        $this->sectionService->updateSectionInfo($sectionId, $name, $section['email']);

        $this->journalService->log(
            'core',
            'section_info_updated',
            'info',
            "Nom de la section {$section['desk_code']} modifié",
            ['section_id' => $sectionId, 'old_name' => $section['name'], 'new_name' => $name],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/functions/section-visibility — show/hide a section from
     * every section picker across the site (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateSectionVisibility(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $sectionId = isset($json['section_id']) ? (int) $json['section_id'] : 0;
        $section = $this->sectionService->getSection($sectionId);
        if ($section === null) {
            return $this->json(['success' => false, 'error' => 'Section introuvable.']);
        }

        $visible = (bool) ($json['visible'] ?? false);
        $this->sectionService->updateSectionVisibility($sectionId, $visible);

        $this->journalService->log(
            'core',
            'section_visibility_changed',
            'info',
            "Visibilité de la section {$section['desk_code']} modifiée",
            ['section_id' => $sectionId, 'visible' => $visible],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/functions/section-color — set or clear a section's
     * explicit color override (AJAX, JSON). Empty/missing color clears the
     * override, reverting to the branch-derived default
     * (Core\Member\SectionService::colorForSection()).
     *
     * @param array<string, string> $params
     */
    public function updateSectionColor(Request $request, array $params): Response
    {
        $json = json_decode($request->getRawBody(), true);
        if (!is_array($json)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.']);
        }

        $csrfToken = (string) ($json['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrfToken)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.']);
        }

        $sectionId = isset($json['section_id']) ? (int) $json['section_id'] : 0;
        $section = $this->sectionService->getSection($sectionId);
        if ($section === null) {
            return $this->json(['success' => false, 'error' => 'Section introuvable.']);
        }

        $color = isset($json['color']) ? (string) $json['color'] : null;
        try {
            $this->sectionService->updateSectionColor($sectionId, $color);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()]);
        }

        $this->journalService->log(
            'core',
            'section_color_changed',
            'info',
            "Couleur de la section {$section['desk_code']} modifiée",
            ['section_id' => $sectionId, 'color' => $color],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'color' => SectionService::colorForSection([
            'desk_code' => $section['desk_code'],
            'branch_sort_order' => $section['branch_sort_order'],
            'color' => $color,
        ])]);
    }
}
