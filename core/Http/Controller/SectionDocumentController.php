<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\SectionDocumentException;
use Core\Member\SectionDocumentService;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Service\IntegerInput;
use Twig\Environment;

/**
 * Staffs page "Documents de section" management — chief-gated (module
 * addendum: "management is chief, same treatment as badges"; intendants
 * see the block read-only via the Staffs page's own $can_edit_section).
 * Every route below is registered role_min: chief directly, same idiom
 * as StaffsController::toggleBadge().
 *
 * `role_min: chief` is the floor, not the answer (SECURITY.md §3): every
 * write below additionally re-checks that the account really is an
 * animateur of the section the document belongs to (Core\Member\
 * SectionStaffAuthorizationService, ARCHITECTURE.md §8.33) — the same
 * narrowing StaffsController applies to decide whether to render the
 * controls at all. A masked button is not a boundary, so the check is
 * repeated here rather than trusted from the page the request claims to
 * come from.
 *
 * Only add() legitimately carries a section_id in its body; update(),
 * delete() and reorder() carry document ids, and their section is
 * resolved from the stored row. A section_id from a request body is never
 * what authorizes that request.
 */
class SectionDocumentController extends AbstractController
{
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    /**
     * The AJAX writes surface their error in the page, so this one is
     * read by a human and is French. add() answers a bare "Forbidden" —
     * see the comment at its refusal.
     */
    private const DENIED_MESSAGE = "Vous n'animez pas cette section.";

    public function __construct(
        protected Environment $twig,
        private SectionDocumentService $service,
        private SectionStaffAuthorizationService $sectionStaffAuthorizationService,
        private ScoutYearResolver $scoutYearResolver,
        private JournalService $journalService
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

        if (($guard = $this->guardCsrf($request, $this->staffsUrl($sectionId))) !== null) {
            return $guard;
        }

        // The one write whose target section legitimately comes from the
        // body — and precisely for that reason, validated against the
        // sections this account actually animates before anything is read
        // from the upload.
        if (!$this->staffsEverySection([$sectionId])) {
            $this->journalRefusal('add', [$sectionId], []);
            // A bare body, like every other forged-request refusal in
            // core/Http/Controller: the page never offers this, so there
            // is no legitimate reader to write a French sentence for.
            return new Response('Forbidden', 403);
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

        // Detection is the sole source of truth — no client-declared fallback
        // (audit M9); a failure yields a sentinel the allowlist rejects.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($content);
        $mimeType = $detected !== false ? $detected : 'application/octet-stream';

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
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        if (($denial = $this->guardDocuments('update', [$id])) !== null) {
            return $denial;
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
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $ids = IntegerInput::idList($data['ids'] ?? []);
        if ($ids === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }
        if (($denial = $this->guardDocuments('reorder', $ids)) !== null) {
            return $denial;
        }

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
        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $id = (int) ($data['id'] ?? 0);
        if (($denial = $this->guardDocuments('delete', [$id])) !== null) {
            return $denial;
        }

        try {
            $this->service->delete($id, AuthSession::getUserAccountId());
        } catch (SectionDocumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }

    private function staffsUrl(int $sectionId): string
    {
        return '/chefs/staffs?section=' . $sectionId;
    }

    /**
     * The shared refusal path for the three writes that name documents
     * rather than a section: resolves each document's section FROM THE
     * STORED ROW, refuses the whole request unless the account animates
     * every one of them, and answers the same 403 whether the document
     * belongs to another section or does not exist at all — an id probe
     * learns nothing either way.
     *
     * @param int[] $documentIds
     */
    private function guardDocuments(string $operation, array $documentIds): ?Response
    {
        $sectionIds = $this->service->findSectionIdsForDocuments($documentIds);

        if ($sectionIds === null || !$this->staffsEverySection($sectionIds)) {
            $this->journalRefusal($operation, $sectionIds ?? [], $documentIds);
            return $this->json(['success' => false, 'error' => self::DENIED_MESSAGE], 403);
        }

        return null;
    }

    /**
     * The sections this account is an animateur of, for the same effective
     * scout year the Staffs page itself renders — so what the page offers
     * and what this controller accepts can never disagree.
     *
     * @return int[]
     */
    private function staffedSectionIds(): array
    {
        $role = AuthSession::getRole();
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear(
            ScoutYearSession::getPreviewId(),
            Role::fromString($role)
        );

        return array_map(
            static fn(array $section): int => (int) $section['id'],
            $this->sectionStaffAuthorizationService->getStaffedSections(
                AuthSession::getEmail() ?? '',
                $role,
                $effectiveYear->id
            )
        );
    }

    /**
     * True when every one of $sectionIds is a section this account
     * animates. All of them, deliberately: reorder() carries a whole
     * list, and authorizing it partially would apply someone else's
     * ordering to the documents that did pass and leave the list
     * inconsistent. An empty set is never authorized.
     *
     * @param int[] $sectionIds
     */
    private function staffsEverySection(array $sectionIds): bool
    {
        if ($sectionIds === []) {
            return false;
        }

        $staffed = $this->staffedSectionIds();
        foreach ($sectionIds as $sectionId) {
            if (!in_array($sectionId, $staffed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Journals a refusal at `security` level. Numeric identifiers only —
     * never a title, a filename or anything else a document carries
     * (SECURITY.md §11).
     *
     * @param int[] $sectionIds
     * @param int[] $documentIds
     */
    private function journalRefusal(string $operation, array $sectionIds, array $documentIds): void
    {
        $this->journalService->log(
            'core',
            'section_document_access_denied',
            'security',
            'Écriture refusée sur un document de section',
            [
                'operation' => $operation,
                'section_ids' => array_values($sectionIds),
                'document_ids' => array_values($documentIds),
            ],
            AuthSession::getUserAccountId()
        );
    }
}
