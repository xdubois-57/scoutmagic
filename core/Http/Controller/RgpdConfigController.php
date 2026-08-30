<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Twig\Environment;

class RgpdConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService,
        private RgpdContentService $rgpdContentService,
        private SettingService $settingService,
        private ModuleManager $moduleManager,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/rgpd
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $mode = $this->settingService->get('rgpd_generation_mode', null, 'default');
        $prompt = $this->settingService->get('rgpd_custom_prompt', null, '');
        $defaultContent = $this->rgpdContentService->getDefaultContent();

        if ($mode === 'default') {
            $currentContent = $defaultContent;
        } else {
            $currentContent = $this->editableContentService->get('rgpd.text', '');
            if ($currentContent === '') {
                $currentContent = $defaultContent;
            }
        }

        // Real availability, not just "the module is installed": an enabled
        // llm_connector with no provider configured (or no model on the
        // CAPABLE tier this document is generated on) used to still show the
        // "Générer" button, and only fail after the click.
        $llmAvailable = $this->rgpdContentService->isAvailable();

        return $this->render('config/rgpd.html.twig', [
            'mode' => $mode,
            'prompt' => $prompt,
            'current_content' => $currentContent,
            'llm_available' => $llmAvailable,
        ]);
    }

    /**
     * POST /config/rgpd/save — save RGPD content and mode
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $mode = (string) ($data['mode'] ?? 'default');
        $content = (string) ($data['content'] ?? '');
        $prompt = (string) ($data['prompt'] ?? '');

        if (!in_array($mode, ['default', 'custom', 'ai'], true)) {
            return $this->json(['success' => false, 'error' => 'Mode invalide.'], 400);
        }

        $userId = AuthSession::getUserAccountId();
        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        // Save mode and prompt
        $this->settingService->setInternal('rgpd_generation_mode', $mode);
        $this->settingService->setInternal('rgpd_custom_prompt', $prompt);

        // Save content
        $this->editableContentService->set('rgpd.text', $content, 'rich_text', $userId);

        $this->journalService->log(
            'core',
            'rgpd_content_updated',
            'info',
            "Contenu RGPD modifié (mode: {$mode})",
            ['mode' => $mode, 'has_prompt' => $prompt !== ''],
            $userId
        );

        return $this->json(['success' => true]);
    }

    /**
     * POST /config/rgpd/generate — generate RGPD content via AI and save it automatically
     *
     * @param array<string, string> $params
     */
    public function generate(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $prompt = (string) ($data['prompt'] ?? '');
        $userId = AuthSession::getUserAccountId();

        if ($userId === null) {
            return $this->json(['success' => false, 'error' => 'Non authentifié.'], 403);
        }

        if (!in_array('llm_connector', $this->moduleManager->getEnabledModuleIds(), true)) {
            return $this->json(['success' => false, 'error' => 'Module IA non activé.'], 400);
        }

        if (!$this->rgpdContentService->isAvailable()) {
            return $this->json([
                'success' => false,
                'error' => "Aucun fournisseur IA utilisable n'est configuré pour la génération de ce document.",
            ], 400);
        }

        // Wrap the ENTIRE flow (generation + auto-save) so that ANY exception,
        // including ones thrown by sanitization or the DB layer during
        // auto-save, always results in a valid JSON error response instead
        // of an uncaught exception (which would produce an HTML error page
        // and break response.json() on the client — this manifests in Safari
        // as "The string did not match the expected pattern.").
        try {
            $generatedContent = $this->rgpdContentService->generateWithAi($prompt);

            // Auto-save the generated content
            $this->settingService->setInternal('rgpd_generation_mode', 'ai');
            $this->settingService->setInternal('rgpd_custom_prompt', $prompt);
            $this->editableContentService->set('rgpd.text', $generatedContent, 'rich_text', $userId);
        } catch (\Throwable $e) {
            // Log detailed error information including stack trace
            $errorDetails = [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace_preview' => array_slice($e->getTrace(), 0, 5),
            ];

            // `error` is a member of event_log.level since the level was
            // added for uncaught throwables (ARCHITECTURE.md §8.6). It was
            // not, and this call site used 'info' because writing 'error'
            // made the INSERT itself throw a PDOException — defeating the
            // whole point of this catch block, which is a clean JSON error
            // response instead of an uncaught exception. A failed AI
            // generation is an error, and the journal page can now filter
            // for it.
            $this->journalService->log(
                'core',
                'rgpd_generation_failed',
                'error',
                'Échec de génération du contenu RGPD via IA',
                $errorDetails,
                $userId
            );

            // Core\View\RgpdGenerationException's three messages (service
            // unavailable, answer still truncated, generated text not naming
            // the unit as data controller) are written for this admin and
            // survive. Everything else — the regex failures in
            // sanitizeHtmlOutput(), a PDO error from the auto-save — gets
            // the sentence below instead. Detail is in the journal entry
            // just above, stack trace included.
            return $this->json([
                'success' => false,
                'error' => 'Échec de génération : ' . UserFacingMessage::from(
                    $e,
                    'le texte produit n\'a pas pu être traité et n\'a pas été enregistré. Réessayez, ou '
                    . 'simplifiez les instructions personnalisées.'
                ),
            ], 500);
        }

        $this->journalService->log(
            'core',
            'rgpd_content_generated',
            'info',
            'Contenu RGPD généré via IA et enregistré automatiquement',
            ['prompt_length' => strlen($prompt)],
            $userId
        );

        return $this->json([
            'success' => true,
            'content' => $generatedContent,
        ]);
    }

    /**
     * POST /config/rgpd/reset — reset to default content
     *
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data)) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        if (($guard = $this->guardCsrfJson($request, (string) ($data['_csrf_token'] ?? ''))) !== null) {
            return $guard;
        }

        $defaultContent = $this->rgpdContentService->getDefaultContent();

        return $this->json([
            'success' => true,
            'content' => $defaultContent,
        ]);
    }
}
