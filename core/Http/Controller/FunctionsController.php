<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Import\FunctionRepository;
use Core\Journal\JournalService;
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
        ['value' => 'admin', 'label' => 'Admin', 'badge_class' => 'bg-danger-subtle text-danger-emphasis'],
    ];

    public function __construct(
        protected Environment $twig,
        private FunctionRepository $functionRepo,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/functions — render the functions page.
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

        return $this->render('config/functions.html.twig', [
            'unconfirmed' => $unconfirmed,
            'confirmed_by_role' => $confirmedByRole,
            'roles' => self::ROLE_DEFINITIONS,
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

        // Validate role is a valid enum value
        if (Role::tryFrom($newRole) === null) {
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

        // Log the change (role change or confirmation)
        $description = $wasConfirmed
            ? "Function {$function['desk_code']} role changed from {$oldRole} to {$newRole}"
            : "Function {$function['desk_code']} confirmed with role {$newRole}";

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
}
