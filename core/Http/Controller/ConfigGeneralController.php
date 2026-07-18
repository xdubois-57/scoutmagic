<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Badge\BadgeException;
use Core\Badge\BadgeService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Module\ModuleException;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

class ConfigGeneralController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ModuleManager $moduleManager,
        private BadgeService $badgeService,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/general — render the configuration page.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $modules = $this->moduleManager->discoverModules();
        $this->badgeService->ensureDefaults();

        return $this->render('config/general.html.twig', [
            'modules' => $modules,
            'badges' => $this->badgeService->getAll(),
            'assigned_badge_ids' => $this->badgeService->getAssignedBadgeIds(),
        ]);
    }

    /**
     * POST /config/general/badge-add — create a new badge (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function addBadge(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        try {
            $badge = $this->badgeService->create((string) ($data['name'] ?? ''));
        } catch (BadgeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'core',
            'badge_created',
            'info',
            "Badge « {$badge->name} » créé",
            ['badge_id' => $badge->id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'badge' => $this->serializeBadge($badge)]);
    }

    /**
     * POST /config/general/badge-update — rename a badge / change its icon (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateBadge(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $badgeId = isset($data['badge_id']) ? (int) $data['badge_id'] : 0;

        try {
            $badge = $this->badgeService->update($badgeId, (string) ($data['name'] ?? ''));
        } catch (BadgeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'core',
            'badge_updated',
            'info',
            "Badge « {$badge->name} » modifié",
            ['badge_id' => $badge->id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'badge' => $this->serializeBadge($badge)]);
    }

    /**
     * POST /config/general/badge-toggle-active — activate/deactivate a badge (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function toggleBadgeActive(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $badgeId = isset($data['badge_id']) ? (int) $data['badge_id'] : 0;
        $active = (bool) ($data['active'] ?? false);

        try {
            $this->badgeService->setActive($badgeId, $active);
        } catch (BadgeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'core',
            $active ? 'badge_activated' : 'badge_deactivated',
            'info',
            $active ? 'Badge réactivé' : 'Badge désactivé',
            ['badge_id' => $badgeId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/general/badge-delete — permanently delete a badge (AJAX, JSON).
     * Refused for default badges and badges already assigned to a member.
     *
     * @param array<string, string> $params
     */
    public function deleteBadge(Request $request, array $params): Response
    {
        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $badgeId = isset($data['badge_id']) ? (int) $data['badge_id'] : 0;

        try {
            $this->badgeService->delete($badgeId);
        } catch (BadgeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'core',
            'badge_deleted',
            'info',
            'Badge supprimé',
            ['badge_id' => $badgeId],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonBody(Request $request): ?array
    {
        $data = json_decode($request->getRawBody(), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array{id: int, name: string, is_default: bool, is_active: bool}
     */
    private function serializeBadge(\Core\Badge\Badge $badge): array
    {
        return [
            'id' => $badge->id,
            'name' => $badge->name,
            'is_default' => $badge->isDefault,
            'is_active' => $badge->isActive,
        ];
    }

    /**
     * POST /config/general/module-toggle — activate/deactivate a module (AJAX).
     *
     * @param array<string, string> $params
     */
    public function toggleModule(Request $request, array $params): Response
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

        $moduleId = (string) ($data['module_id'] ?? '');
        $enabled = (bool) ($data['enabled'] ?? false);

        if ($moduleId === '') {
            return $this->json(['success' => false, 'error' => 'Identifiant de module manquant.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        try {
            if ($enabled) {
                $this->moduleManager->activate($moduleId, $userId);
            } else {
                $this->moduleManager->deactivate($moduleId, $userId);
            }
        } catch (ModuleException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }
}
