<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Documents\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Service\IntegerInput;
use Modules\Documents\Repository\Document;
use Modules\Documents\Service\DocumentException;
use Modules\Documents\Service\DocumentService;
use Modules\Documents\Service\DocumentVisibility;
use Twig\Environment;

/**
 * « Espace chefs d'U › Documents »: the list, its order, and the one
 * editing screen (title, description, visibility, and an optional new
 * file). `role_min: admin` on every route (module.json).
 */
class DocumentsAdminController extends AbstractController
{
    private const LIST_URL = '/admin/documents';

    public function __construct(
        protected Environment $twig,
        private DocumentService $documentService,
        private string $baseUrl
    ) {
    }

    /**
     * GET /admin/documents
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@documents/manage.html.twig', [
            'documents' => $this->documentService->all(),
            'base_url' => rtrim($this->baseUrl, '/'),
        ]);
    }

    /**
     * GET /admin/documents/nouveau
     *
     * @param array<string, string> $params
     */
    public function createForm(Request $request, array $params): Response
    {
        return $this->renderForm(null, [
            'title' => '',
            'description' => '',
            'visibility' => DocumentVisibility::PUBLIC->value,
        ]);
    }

    /**
     * POST /admin/documents/nouveau
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $guard = $this->guardCsrf($request, '/admin/documents/nouveau');
        if ($guard !== null) {
            return $guard;
        }

        $submitted = $this->submitted($request);
        try {
            $document = $this->documentService->create(
                $submitted['title'],
                $submitted['description'],
                $submitted['visibility'],
                $request->getFile('file') ?? [],
                AuthSession::getUserAccountId()
            );
        } catch (DocumentException $e) {
            return $this->renderForm(null, $submitted, $e->getMessage());
        }

        FlashMessage::set('success', 'Document « ' . $document->title . ' » ajouté.');
        return $this->redirect(self::LIST_URL);
    }

    /**
     * GET /admin/documents/{id}/modifier
     *
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): Response
    {
        $document = $this->documentFrom($params);
        if ($document === null) {
            return $this->notFound();
        }

        return $this->renderForm($document, [
            'title' => $document->title,
            'description' => (string) $document->description,
            'visibility' => $document->visibility->value,
        ]);
    }

    /**
     * POST /admin/documents/{id}/modifier
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $document = $this->documentFrom($params);
        if ($document === null) {
            return $this->notFound();
        }
        $guard = $this->guardCsrf($request, '/admin/documents/' . $document->id . '/modifier');
        if ($guard !== null) {
            return $guard;
        }

        $submitted = $this->submitted($request);
        try {
            $updated = $this->documentService->update(
                $document->id,
                $submitted['title'],
                $submitted['description'],
                $submitted['visibility'],
                $request->getFile('file'),
                AuthSession::getUserAccountId()
            );
        } catch (DocumentException $e) {
            return $this->renderForm($document, $submitted, $e->getMessage());
        }

        FlashMessage::set('success', 'Document « ' . $updated->title . ' » enregistré.');
        return $this->redirect(self::LIST_URL);
    }

    /**
     * POST /admin/documents/reorder (JSON, from partials/list_editor).
     *
     * @param array<string, string> $params
     */
    public function reorder(Request $request, array $params): Response
    {
        $data = $this->decodeJson($request);
        if ($data instanceof Response) {
            return $data;
        }
        $ids = IntegerInput::idList($data['ids'] ?? []);
        if ($ids === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        $this->documentService->reorder($ids);
        return $this->json(['success' => true]);
    }

    /**
     * POST /admin/documents/delete (JSON, from partials/list_editor).
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = $this->decodeJson($request);
        if ($data instanceof Response) {
            return $data;
        }
        $id = IntegerInput::id($data['id'] ?? null);
        if ($id === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        try {
            $this->documentService->delete($id, AuthSession::getUserAccountId());
        } catch (DocumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json(['success' => true]);
    }

    /**
     * @param array{title: string, description: string, visibility: string} $values
     */
    private function renderForm(?Document $document, array $values, ?string $error = null): Response
    {
        $visibilities = [];
        foreach (DocumentVisibility::cases() as $case) {
            $visibilities[] = ['value' => $case->value, 'label' => $case->label()];
        }

        $response = $this->render('@documents/form.html.twig', [
            'document' => $document,
            'values' => $values,
            'visibilities' => $visibilities,
            'error' => $error,
            'max_mb' => (int) (DocumentService::MAX_BYTES / 1024 / 1024),
            'title_max' => DocumentService::TITLE_MAX_LENGTH,
            'description_max' => DocumentService::DESCRIPTION_MAX_LENGTH,
            'breadcrumb_current' => $document === null ? 'Ajouter un document' : $document->title,
        ]);

        return $error === null ? $response : $response->setStatusCode(422);
    }

    /**
     * @return array{title: string, description: string, visibility: string}
     */
    private function submitted(Request $request): array
    {
        return [
            'title' => (string) $request->getBody('title', ''),
            'description' => (string) $request->getBody('description', ''),
            'visibility' => (string) $request->getBody('visibility', ''),
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function documentFrom(array $params): ?Document
    {
        $id = IntegerInput::id($params['id'] ?? null);
        return $id === null ? null : $this->documentService->findById($id);
    }

    /**
     * @return array<string, mixed>|Response the decoded payload, or the
     *                                       refusal to return as-is
     */
    private function decodeJson(Request $request): array|Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }
        $guard = $this->guardCsrfJson($request, is_string($data['_csrf_token'] ?? null) ? $data['_csrf_token'] : null);
        return $guard ?? $data;
    }
}
