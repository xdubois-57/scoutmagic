<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\View\ConfigurationMode;
use Core\View\EditableContentAuthorizer;
use Core\View\EditableContentService;
use Twig\Environment;

class EditableContentController extends AbstractController
{
    private ?JournalService $journalService = null;

    /**
     * @param EditableContentAuthorizer[] $authorizers the keys whose write
     *        role is narrower than this endpoint's own — see
     *        {@see requireWriteAccess()}. Optional and trailing so the
     *        many call sites that build this controller with two
     *        arguments keep working; an empty list is the behaviour this
     *        endpoint had before free-text pages existed.
     */
    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService,
        private array $authorizers = []
    ) {
    }

    /**
     * The per-key re-check SECURITY.md §3 asks for: « `role_min` is a
     * floor, never the whole answer ».
     *
     * Both endpoints below are `role_min: admin`, which was the whole
     * answer for as long as every editable key lived on a page an admin
     * could also read. A free-text page filed in the Configuration menu
     * is read at `superadmin` while its body is written here under
     * `page_content_{id}`, so without this an admin refused the page
     * could still rewrite what a superadmin reads (ARCHITECTURE.md
     * §8.115).
     *
     * Returns the refusal, or null when the caller may write the key.
     * An unrecognised key is nobody's business and keeps the route's
     * own floor, so this can only ever narrow.
     */
    private function requireWriteAccess(string $key): ?Response
    {
        foreach ($this->authorizers as $authorizer) {
            $required = $authorizer->roleMinForKey($key);
            if ($required === null) {
                continue;
            }

            if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::fromString($required))) {
                // The same sentence whatever the reason, and no mention
                // of what the key names: an answer that distinguished
                // "no such page" from "not your page" would map out
                // which ids exist.
                return $this->json(['success' => false, 'error' => self::FORBIDDEN_MESSAGE], 403);
            }
        }

        return null;
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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

        // An `image` holds a `files` id and nothing else — that is what the
        // type means, and what `editable_image()` reads back. Saying so
        // here is the second half of the fix the service carries: the
        // client picks the type, so the client must not be able to pick a
        // type in order to smuggle a body past what that type accepts.
        if ($type === 'image' && $value !== '' && preg_match('/^\d+$/', $value) !== 1) {
            return $this->json(['success' => false, 'error' => 'Une image se réfère à un fichier envoyé.'], 400);
        }

        if (($refusal = $this->requireWriteAccess($key)) !== null) {
            return $refusal;
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        $stored = $this->editableContentService->set($key, $value, $type, $userId);

        $this->journalService?->log(
            'core',
            'content_updated',
            'info',
            'Contenu éditable modifié',
            // The address is already event_log.ip_address.
            ['key' => $key, 'type' => $type],
            $userId
        );

        // `value` is what was STORED, which the sanitizer may have pruned.
        // The editor repaints the block with it rather than with its own
        // copy of what it sent, so the page and the database agree from
        // the moment the save returns (issue #306).
        return $this->json(['success' => true, 'value' => $stored]);
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
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
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

        // An `image` holds a `files` id and nothing else — that is what the
        // type means, and what `editable_image()` reads back. Saying so
        // here is the second half of the fix the service carries: the
        // client picks the type, so the client must not be able to pick a
        // type in order to smuggle a body past what that type accepts.
        if ($type === 'image' && $value !== '' && preg_match('/^\d+$/', $value) !== 1) {
            return $this->json(['success' => false, 'error' => 'Une image se réfère à un fichier envoyé.'], 400);
        }

        if (($refusal = $this->requireWriteAccess($key)) !== null) {
            return $refusal;
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        $stored = $this->editableContentService->set($key, $value, $type, $userId);

        $this->journalService?->log(
            'core',
            'content_updated',
            'info',
            'Contenu éditable modifié',
            // The address is already event_log.ip_address.
            ['key' => $key, 'type' => $type],
            $userId
        );

        // `value` is what was STORED, which the sanitizer may have pruned.
        // The editor repaints the block with it rather than with its own
        // copy of what it sent, so the page and the database agree from
        // the moment the save returns (issue #306).
        return $this->json(['success' => true, 'value' => $stored]);
    }
}
