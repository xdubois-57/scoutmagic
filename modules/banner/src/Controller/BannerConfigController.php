<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Banner\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Banner\Service\BannerException;
use Modules\Banner\Service\BannerService;
use Twig\Environment;

/**
 * /config/banner — the "Bannière" configuration page (module spec §2),
 * restricted to chefs d'U specifically (Core\Member\MemberService::
 * isUnitChief() — STAFFDU section membership, not merely Role::ADMIN,
 * which an unrelated admin-role function could also carry). module.json's
 * route role_min stays 'admin' (matching Espace Admin's own menu-section
 * floor — a plain chief never even sees this menu), the finer check below
 * narrows it further at runtime, same precedent as Modules\Retro\Service\
 * BoardService's own moderation/visibility gating.
 *
 * Each banner's own formatted text is saved through the generic core
 * endpoint (Core\Http\Controller\EditableContentController::updateField(),
 * POST /api/rich-text-content) via partials/rich_text_field.html.twig —
 * this controller only manages the list itself (add/reorder/activate/
 * delete), via partials/list_editor.html.twig.
 */
class BannerConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private BannerService $bannerService,
        private JournalService $journalService,
        private MemberService $memberService,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $forbidden = $this->requireUnitChief();
        if ($forbidden !== null) {
            return $forbidden;
        }

        return $this->render('@banner/config.html.twig', [
            'banners' => $this->bannerService->getAllForConfig(),
        ]);
    }

    /**
     * POST /config/banner/add (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $banner = $this->bannerService->create();

        $this->journalService->log(
            'banner',
            'banner_created',
            'info',
            'Bannière créée',
            ['banner_id' => $banner->id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true, 'banner_id' => $banner->id]);
    }

    /**
     * POST /config/banner/active (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateActive(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($data['id'] ?? 0);
        $active = (bool) ($data['active'] ?? false);

        try {
            $this->bannerService->setActive($id, $active);
        } catch (BannerException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'banner',
            'banner_active_changed',
            'info',
            'Statut actif d\'une bannière modifié',
            ['banner_id' => $id, 'active' => $active],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/banner/role-min (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function updateRoleMin(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($data['id'] ?? 0);
        $roleMin = (string) ($data['role_min'] ?? '');

        try {
            $this->bannerService->setRoleMin($id, $roleMin);
        } catch (BannerException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'banner',
            'banner_role_min_changed',
            'info',
            'Visibilité minimale d\'une bannière modifiée',
            ['banner_id' => $id, 'role_min' => $roleMin],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/banner/reorder (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $ids = is_array($data['ids'] ?? null) ? array_map('intval', $data['ids']) : [];
        $this->bannerService->reorder($ids);

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/banner/delete (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = $this->decodeAndAuthorize($request);
        if ($data instanceof Response) {
            return $data;
        }

        $id = (int) ($data['id'] ?? 0);

        try {
            $this->bannerService->delete($id);
        } catch (BannerException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalService->log(
            'banner',
            'banner_deleted',
            'info',
            'Bannière supprimée',
            ['banner_id' => $id],
            AuthSession::getUserAccountId()
        );

        return $this->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>|Response an array on success, or an
     *                                       error Response to return as-is
     */
    private function decodeAndAuthorize(Request $request): array|Response
    {
        $forbidden = $this->requireUnitChief();
        if ($forbidden !== null) {
            return $forbidden;
        }

        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        return $data;
    }

    private function requireUnitChief(): ?Response
    {
        $email = AuthSession::getEmail();
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        if ($email === null || !$this->memberService->isUnitChief($email, $scoutYearId)) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        return null;
    }
}
