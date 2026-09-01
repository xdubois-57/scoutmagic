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
use Core\View\RgpdGenerationRunner;
use Twig\Environment;

class RgpdConfigController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService,
        private RgpdContentService $rgpdContentService,
        private SettingService $settingService,
        private ModuleManager $moduleManager,
        private JournalService $journalService,
        /**
         * Where the generation actually happens — a scheduled task, not
         * this request. Null on an installation with no scheduler wired,
         * which answers « indisponible » rather than silently going back
         * to a call that times out.
         */
        private ?RgpdGenerationRunner $generationRunner = null
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
            // Rendered rather than fetched: the generation runs in a
            // scheduled task and outlives by minutes the request that
            // asked for it, so the page has to be RIGHT on first paint —
            // a reload mid-run picks the ticker back up instead of
            // showing an idle button beside a job in flight.
            'generation_running' => $this->generationRunner?->isRunning() ?? false,
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
     * POST /config/rgpd/generate — **queue** the generation; the page
     * polls generateStatus() for the outcome.
     *
     * It used to generate inside this request, and the request is where
     * it broke: the system prompt carries the whole 135 KB reference
     * document, the model is asked for a ten-section HTML document across
     * up to three sequential calls, and the provider timeout of ninety
     * seconds cut it every time — « Échec de génération : Timeout lors de
     * l'appel au fournisseur IA » on a call that had not failed so much
     * as not finished. Raising the number only moves the wall: a shared
     * host's front-end proxy cuts long before a model finishes writing
     * ten sections. So it goes where every other multi-minute job of this
     * site goes (see Core\View\RgpdGenerationRunner).
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

        if ($this->generationRunner === null) {
            return $this->json([
                'success' => false,
                'error' => "La génération en arrière-plan n'est pas disponible sur cette installation.",
            ], 503);
        }

        if (!$this->generationRunner->scheduleBackgroundRun($prompt, $userId)) {
            return $this->json([
                'success' => false,
                'error' => 'Une génération est déjà en cours. Attendez qu\'elle se termine.',
            ], 409);
        }

        $this->journalService->log(
            'core',
            'rgpd_generation_queued',
            'info',
            'Génération du contenu RGPD demandée',
            ['prompt_length' => strlen($prompt)],
            $userId
        );

        return $this->json(['success' => true, 'queued' => true]);
    }

    /**
     * GET /config/rgpd/generate/status — « où en est-elle ? ».
     *
     * The generation runs on the next cron pass, so the page has to be
     * able to say « en cours » across a reload and a closed laptop alike:
     * the state is in the settings table, never in the session that
     * asked.
     *
     * @param array<string, string> $params
     */
    public function generateStatus(Request $request, array $params): Response
    {
        if ($this->generationRunner === null) {
            return $this->json(['success' => true, 'running' => false, 'status' => 'idle']);
        }

        return $this->json(['success' => true] + $this->generationRunner->status());
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
