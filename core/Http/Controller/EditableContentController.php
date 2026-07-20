<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\ConfigurationMode;
use Core\View\EditableContentService;
use Twig\Environment;

class EditableContentController extends AbstractController
{
    private ?JournalService $journalService = null;

    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService
    ) {
    }

    public function setJournalService(JournalService $journalService): void
    {
        $this->journalService = $journalService;
    }

    /**
     * POST /api/editable-content — update editable content (AJAX, JSON).
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        // Validate CSRF
        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        // Configuration mode must be active
        if (!ConfigurationMode::isActive()) {
            return $this->json(['success' => false, 'error' => 'Le mode configuration n\'est pas actif.'], 403);
        }

        $key = trim((string) ($data['key'] ?? ''));
        $value = (string) ($data['value'] ?? '');
        $type = (string) ($data['type'] ?? 'rich_text');

        if ($key === '') {
            return $this->json(['success' => false, 'error' => 'Clé de contenu manquante.'], 400);
        }

        if (!in_array($type, ['rich_text', 'image'], true)) {
            return $this->json(['success' => false, 'error' => 'Type de contenu invalide.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        $this->editableContentService->set($key, $value, $type, $userId);

        $this->journalService?->log(
            'core', 'content_updated', 'info', 'Contenu éditable modifié',
            ['key' => $key, 'type' => $type, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /api/rich-text-content — same as update() but never gated
     * behind configuration mode: for admin pages that manage their own
     * dynamic list of rich-text items (Core\View\templates\partials\
     * rich_text_field.html.twig / rich-text-field.js), where the route's
     * own role_min already provides the authorization that configuration
     * mode exists to provide for in-place editing of fixed page content.
     * Reusable as-is by any future module using that same partial — no
     * per-module save endpoint needed for the text itself.
     *
     * @param array<string, string> $params
     */
    public function updateField(Request $request, array $params): Response
    {
        $rawBody = $request->getRawBody();
        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $csrf = (string) ($data['_csrf_token'] ?? '');
        if (!CsrfGuard::validateToken($csrf)) {
            return $this->json(['success' => false, 'error' => 'Session expirée.'], 403);
        }

        $key = trim((string) ($data['key'] ?? ''));
        $value = (string) ($data['value'] ?? '');
        $type = (string) ($data['type'] ?? 'rich_text');

        if ($key === '') {
            return $this->json(['success' => false, 'error' => 'Clé de contenu manquante.'], 400);
        }

        if (!in_array($type, ['rich_text', 'image'], true)) {
            return $this->json(['success' => false, 'error' => 'Type de contenu invalide.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        $this->editableContentService->set($key, $value, $type, $userId);

        $this->journalService?->log(
            'core', 'content_updated', 'info', 'Contenu éditable modifié',
            ['key' => $key, 'type' => $type, 'ip' => $_SERVER['REMOTE_ADDR'] ?? ''],
            $userId
        );

        return $this->json(['success' => true]);
    }
}
