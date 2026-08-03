<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionDocumentException;
use Core\Member\SectionDocumentService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Twig\Environment;

/**
 * Staffs page "Documents de section" management — chief-gated (module
 * addendum: "management is chief, same treatment as badges"; intendants
 * see the block read-only via the Staffs page's own $can_edit_section).
 * Every route below is registered role_min: chief directly, same idiom
 * as StaffsController::toggleBadge().
 */
class SectionDocumentController extends AbstractController
{
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        protected Environment $twig,
        private SectionDocumentService $service
    ) {
    }

    /**
     * POST /chefs/staffs/documents — upload (multipart form: file,
     * section_id, scout_year_id, title, description).
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        $sectionId = (int) $request->getBody('section_id', '0');
        $scoutYearId = (int) $request->getBody('scout_year_id', '0');

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect($this->staffsUrl($sectionId));
        }

        $file = $request->getFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            FlashMessage::set('error', "Erreur lors de l'envoi du fichier.");
            return $this->redirect($this->staffsUrl($sectionId));
        }
        if ($file['size'] > self::MAX_SIZE_BYTES) {
            FlashMessage::set('error', 'Le fichier dépasse la taille maximale autorisée (20 Mo).');
            return $this->redirect($this->staffsUrl($sectionId));
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            FlashMessage::set('error', 'Impossible de lire le fichier envoyé.');
            return $this->redirect($this->staffsUrl($sectionId));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content) ?: (string) $file['type'];

        $title = trim((string) $request->getBody('title', ''));
        $description = trim((string) $request->getBody('description', ''));

        try {
            $this->service->upload(
                $sectionId, $scoutYearId, $content, $mimeType, (string) $file['name'],
                $title, $description !== '' ? $description : null, AuthSession::getUserAccountId()
            );
            FlashMessage::set('success', 'Document ajouté.');
        } catch (SectionDocumentException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect($this->staffsUrl($sectionId));
    }

    /**
     * POST /chefs/staffs/documents/{id} — auto-save title/description on
     * blur (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (!CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        try {
            $this->service->updateTitleAndDescription(
                $id, (string) ($data['title'] ?? ''), $data['description'] ?? null, AuthSession::getUserAccountId()
            );
        } catch (SectionDocumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }

    /**
     * POST /chefs/staffs/documents/reorder — flat URL, ids-in-body, the
     * exact shape public/assets/js/list-editor.js's built-in reorder
     * wiring posts (see partials/list_editor.html.twig), reused as-is
     * for this list. section_id/scout_year_id are deliberately NOT part
     * of the request — a single list_editor instance's ids array is
     * always scoped to one accordion year already, so they're resolved
     * server-side from the first id instead (Core\Member\
     * SectionDocumentService::reorder()).
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (!CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $ids = array_map('intval', is_array($data['ids'] ?? null) ? $data['ids'] : []);
        $this->service->reorder($ids);

        return $this->json(['success' => true]);
    }

    /**
     * POST /chefs/staffs/documents/delete — flat URL, id-in-body, same
     * list-editor.js built-in wiring as reorder() above.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        if (!CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        try {
            $this->service->delete((int) ($data['id'] ?? 0), AuthSession::getUserAccountId());
        } catch (SectionDocumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }

    private function staffsUrl(int $sectionId): string
    {
        return '/chefs/staffs?section=' . $sectionId;
    }
}
