<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Badge\BadgeException;
use Core\Badge\BadgeService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Configuration > Badges — transversal role badges (Infirmier, Trésorier,
 * "Référent {section}"...), see ARCHITECTURE.md §8.11. Split out of the
 * former ConfigGeneralController (which also carried the module registry
 * and the configuration-mode toggle) so each page has its own
 * single-concern controller (AGENTS.md).
 */
class ConfigBadgesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private BadgeService $badgeService,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/badges — render the badges page. ensureDefaults()/
     * syncSectionReferentBadges() are idempotent, self-healing seeds
     * (ARCHITECTURE §8.11) that must run on every load of *this* page —
     * they moved here with the badges concern itself, not left behind on
     * the modules page "just in case".
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $this->badgeService->ensureDefaults();
        $this->badgeService->syncSectionReferentBadges();

        return $this->render('config/badges.html.twig', [
            'badges' => $this->badgeService->getAll(),
            'assigned_badge_ids' => $this->badgeService->getAssignedBadgeIds(),
        ]);
    }

    /**
     * POST /config/badges/add — create a new badge (AJAX, JSON).
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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
     * POST /config/badges/update — rename a badge (AJAX, JSON).
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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
     * POST /config/badges/toggle-active — activate/deactivate a badge (AJAX, JSON).
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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
     * POST /config/badges/delete — permanently delete a badge (AJAX, JSON).
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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
}
