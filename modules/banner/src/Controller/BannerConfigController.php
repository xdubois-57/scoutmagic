<?php

declare(strict_types=1);

namespace Modules\Banner\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\Banner\Service\BannerException;
use Modules\Banner\Service\BannerService;
use Twig\Environment;

/**
 * /config/banner — the "Bannière" configuration page (module spec §2).
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
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
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
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        return $data;
    }
}
