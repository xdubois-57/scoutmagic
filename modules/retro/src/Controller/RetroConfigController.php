<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Service\IntegerInput;
use Modules\Retro\Service\ModerationService;
use Twig\Environment;

/**
 * /config/retro — the module-wide settings page (role thresholds, default
 * comment length/vote budget, polling interval, AI moderation mode),
 * distinct from Controller\RetroChiefController's per-board create/edit
 * form. Restricted to chefs d'U specifically (Core\Member\MemberService::
 * isUnitChief() — STAFFDU section membership, not merely Role::ADMIN),
 * same precedent as Modules\Banner\Controller\BannerConfigController.
 */
class RetroConfigController extends AbstractController
{
    private const ROLE_MIN_CREATE_VALUES = ['intendant', 'chief', 'admin'];
    private const ROLE_MIN_CLOSE_VALUES = ['chief', 'admin'];
    private const MODERATION_MODES = ['disabled', 'warning', 'enforced'];
    private const MIN_MAX_COMMENT_LENGTH = 120;
    private const MAX_MAX_COMMENT_LENGTH = 200;

    public function __construct(
        protected Environment $twig,
        private SettingService $settingService,
        private JournalService $journalService,
        private MemberService $memberService,
        private ScoutYearService $scoutYearService,
        private ?ModerationService $moderationService = null
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

        return $this->render('@retro/settings.html.twig', $this->buildContext());
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $forbidden = $this->requireUnitChief();
        if ($forbidden !== null) {
            return $forbidden;
        }

        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            $context = $this->buildContext();
            $context['submit_error'] = self::SESSION_EXPIRED_MESSAGE;
            return $this->render('@retro/settings.html.twig', $context)->setStatusCode(403);
        }

        $roleMinCreate = (string) $request->getBody('retro_role_min_create_board', 'intendant');
        $roleMinClose = (string) $request->getBody('retro_role_min_close_board', 'chief');
        $maxCommentLength = (int) $request->getBody('retro_default_max_comment_length', '140');
        $voteBudget = (int) $request->getBody('retro_default_vote_budget', '5');
        $pollingInterval = (int) $request->getBody('retro_polling_interval_seconds', '8');
        $moderationMode = (string) $request->getBody('retro_moderation_mode', 'disabled');

        if (!in_array($roleMinCreate, self::ROLE_MIN_CREATE_VALUES, true)) {
            return $this->saveError('Rôle minimum de création invalide.');
        }
        if (!in_array($roleMinClose, self::ROLE_MIN_CLOSE_VALUES, true)) {
            return $this->saveError('Rôle minimum de clôture invalide.');
        }
        if ($maxCommentLength < self::MIN_MAX_COMMENT_LENGTH || $maxCommentLength > self::MAX_MAX_COMMENT_LENGTH) {
            return $this->saveError('La longueur maximale par défaut doit être comprise entre ' . self::MIN_MAX_COMMENT_LENGTH . ' et ' . self::MAX_MAX_COMMENT_LENGTH . '.');
        }
        if ($voteBudget < 1 || $voteBudget > IntegerInput::UNSIGNED_INT_MAX) {
            return $this->saveError('Le budget de points par défaut doit être d\'au moins 1.');
        }
        if ($pollingInterval < 3 || $pollingInterval > 60) {
            return $this->saveError('L\'intervalle d\'actualisation doit être compris entre 3 et 60 secondes.');
        }
        if (!in_array($moderationMode, self::MODERATION_MODES, true)) {
            return $this->saveError('Mode de modération invalide.');
        }
        // Never trust the client here either — "warning"/"enforced" only
        // ever have an effect when the AI connector is actually available
        // (Controller\RetroBoardController::resolveModerationMode() already
        // re-enforces this at read time), but persisting a value the UI
        // itself hid the option for would be a confusing, silently-inert
        // setting for an admin to stumble on later.
        if ($moderationMode !== 'disabled' && !$this->aiAvailable()) {
            return $this->saveError('La modération automatique nécessite un connecteur IA actif.');
        }

        $this->settingService->set('retro_role_min_create_board', $roleMinCreate, 'retro');
        $this->settingService->set('retro_role_min_close_board', $roleMinClose, 'retro');
        $this->settingService->set('retro_default_max_comment_length', (string) $maxCommentLength, 'retro');
        $this->settingService->set('retro_default_vote_budget', (string) $voteBudget, 'retro');
        $this->settingService->set('retro_polling_interval_seconds', (string) $pollingInterval, 'retro');
        $this->settingService->set('retro_moderation_mode', $moderationMode, 'retro');

        $this->journalService->log(
            'retro', 'config_updated', 'info', 'Configuration des rétrospectives modifiée',
            [], (int) AuthSession::getUserAccountId()
        );

        return $this->redirect('/config/retro');
    }

    private function saveError(string $message): Response
    {
        $context = $this->buildContext();
        $context['submit_error'] = $message;
        return $this->render('@retro/settings.html.twig', $context)->setStatusCode(422);
    }

    private function aiAvailable(): bool
    {
        return $this->moderationService !== null && $this->moderationService->isAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        return [
            'ai_available' => $this->aiAvailable(),
            'retro_role_min_create_board' => (string) ($this->settingService->get(
                'retro_role_min_create_board',
                'retro'
            ) ?: 'intendant'),
            'retro_role_min_close_board' => (string) ($this->settingService->get(
                'retro_role_min_close_board',
                'retro'
            ) ?: 'chief'),
            'retro_default_max_comment_length' => (int) ($this->settingService->get('retro_default_max_comment_length',
                'retro') ?: 140),
            'retro_default_vote_budget' => (int) ($this->settingService->get(
                'retro_default_vote_budget',
                'retro'
            ) ?: 5),
            'retro_polling_interval_seconds' => (int) ($this->settingService->get('retro_polling_interval_seconds',
                'retro') ?: 8),
            'retro_moderation_mode' => (string) ($this->settingService->get(
                'retro_moderation_mode',
                'retro'
            ) ?: 'disabled'),
            'csrf_token' => CsrfGuard::generateToken(),
        ];
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
