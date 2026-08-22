<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Controller;

use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Module\ModuleManager;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\RetroException;
use Twig\Environment;

/**
 * Chief-facing board management — list/create/edit/close/regenerate-link,
 * all reached via internal numeric board ids (never exposed to anonymous
 * participants, unlike Controller\RetroBoardController's token-based
 * routes). module.json's route role_min is deliberately the loosest legal
 * value for each action ('intendant' for create/edit, 'chief' for
 * close/regenerate) — the module spec makes both thresholds configurable
 * (retro_role_min_create_board/retro_role_min_close_board), so the real
 * check happens at runtime against the setting via hasRequiredRole().
 */
class RetroChiefController extends AbstractController
{
    public function __construct(
        Environment $twig,
        private BoardRepository $boardRepository,
        private BoardService $boardService,
        private SettingService $settingService,
        private ScoutYearResolver $scoutYearResolver,
        private ModuleManager $moduleManager
    ) {
        parent::__construct($twig);
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $entries = array_map(
            fn(Board $b) => ['board' => $b, 'public_url' => $this->boardService->publicUrl($b)],
            $this->boardRepository->findAllOrderedByCreated()
        );

        return $this->render('@retro/list.html.twig', [
            'boards' => array_values(array_filter($entries, fn(array $e) => !$e['board']->isArchived())),
            'archived_boards' => array_values(array_filter($entries, fn(array $e) => $e['board']->isArchived())),
            'can_create' => $this->hasRequiredRole('retro_role_min_create_board', 'intendant'),
            'can_close' => $this->hasRequiredRole('retro_role_min_close_board', 'chief'),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        if (!$this->hasRequiredRole('retro_role_min_create_board', 'intendant')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        return $this->render('@retro/config.html.twig', $this->formContext(null));
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_create_board', 'intendant')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        try {
            $this->boardService->create(
                (string) $request->getBody('title', ''),
                $this->nullableInt($request->getBody('calendar_event_id', '')),
                $this->nullableString($request->getBody('board_date', '')),
                $request->getBody('listed', '1') === '1',
                (string) $request->getBody('vote_mode', 'unlimited'),
                (int) $request->getBody('vote_budget', '5'),
                $request->getBody('votes_visible', '1') === '1',
                (string) $request->getBody('anti_duplicate_mode', 'cookie'),
                (int) $request->getBody('max_comment_length', '140'),
                (string) $request->getBody('auto_close_delay', '7d'),
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId(),
                $request->getBody('close_notify_enabled', '1') === '1',
                $this->nullableString($request->getBody('close_notify_email', '')),
                (string) $request->getBody('link_visibility', 'chief')
            );
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/retro/create');
        }

        FlashMessage::set('success', 'Rétrospective créée.');
        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        if (!$this->hasRequiredRole('retro_role_min_create_board', 'intendant')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        $board = $this->boardRepository->findById((int) ($params['id'] ?? 0));
        if ($board === null) {
            return new Response('Not Found', 404);
        }

        return $this->render('@retro/config.html.twig', $this->formContext($board));
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_create_board', 'intendant')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        $id = (int) ($params['id'] ?? 0);

        try {
            $this->boardService->update(
                $id,
                (string) $request->getBody('title', ''),
                $this->nullableInt($request->getBody('calendar_event_id', '')),
                $this->nullableString($request->getBody('board_date', '')),
                $request->getBody('listed', '1') === '1',
                (string) $request->getBody('vote_mode', 'unlimited'),
                (int) $request->getBody('vote_budget', '5'),
                $request->getBody('votes_visible', '1') === '1',
                (string) $request->getBody('anti_duplicate_mode', 'cookie'),
                (int) $request->getBody('max_comment_length', '140'),
                (string) $request->getBody('auto_close_delay', '7d'),
                Role::fromString(AuthSession::getRole()),
                AuthSession::getUserAccountId(),
                $request->getBody('close_notify_enabled', '1') === '1',
                $this->nullableString($request->getBody('close_notify_email', '')),
                (string) $request->getBody('link_visibility', 'chief')
            );
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/retro/' . $id . '/edit');
        }

        FlashMessage::set('success', 'Rétrospective mise à jour.');
        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function close(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_close_board', 'chief')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        try {
            $this->boardService->close((int) ($params['id'] ?? 0), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Rétrospective clôturée.');
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function reopen(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_close_board', 'chief')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        try {
            $this->boardService->reopen((int) ($params['id'] ?? 0), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Rétrospective réouverte.');
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function archive(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_close_board', 'chief')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        try {
            $this->boardService->archive((int) ($params['id'] ?? 0), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Rétrospective archivée.');
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function unarchive(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_close_board', 'chief')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        try {
            $this->boardService->unarchive((int) ($params['id'] ?? 0), AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Rétrospective désarchivée.');
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/retro');
    }

    /**
     * @param array<string, string> $params
     */
    public function regenerateLink(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            FlashMessage::set('error', 'Jeton CSRF invalide.');
            return $this->redirect('/retro');
        }
        if (!$this->hasRequiredRole('retro_role_min_close_board', 'chief')) {
            return (new Response('', 403))->setBody('Forbidden');
        }

        $id = (int) ($params['id'] ?? 0);

        try {
            $this->boardService->regenerateLink($id, AuthSession::getUserAccountId());
            FlashMessage::set('success', 'Lien régénéré — l\'ancien lien ne fonctionne plus.');
        } catch (RetroException $e) {
            FlashMessage::set('error', $e->getMessage());
        }

        return $this->redirect('/retro/' . $id . '/edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function formContext(?Board $board): array
    {
        $viewerRole = Role::fromString(AuthSession::getRole());
        $isAdmin = $viewerRole->hasAccess(Role::ADMIN);

        $email = AuthSession::getEmail();
        $scoutYearId = $this->scoutYearResolver->getEffectiveYear(ScoutYearSession::getPreviewId(), $viewerRole)->id;
        $sectionId = $email !== null ? $this->boardService->resolvePrincipalSectionId($email, $scoutYearId) : null;

        $events = $this->boardService->listEventsForPicker($sectionId, $isAdmin, $viewerRole);
        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');

        return [
            'board' => $board,
            'breadcrumb_trail' => [
                ['label' => 'Rétrospectives', 'url' => '/retro'],
            ],
            'events' => $events,
            'calendar_enabled' => in_array('calendar', $this->moduleManager->getEnabledModuleIds(), true),
            'default_max_comment_length' => (int) ($this->settingService->get('retro_default_max_comment_length', 'retro') ?: 140),
            'default_vote_budget' => (int) ($this->settingService->get('retro_default_vote_budget', 'retro') ?: 5),
            'public_url' => $board !== null ? $baseUrl . $this->boardService->publicUrl($board) : null,
            // Create-form default only — "the email address is the one
            // logged-in at the time the retrospective is created" (module
            // spec). An edit form always shows the board's own stored
            // value instead (via board.closeNotifyEmail).
            'default_notify_email' => $board === null ? ($email ?? '') : '',
            'link_visibilities' => [
                ['value' => 'identified', 'label' => 'Identifié'],
                ['value' => 'chief', 'label' => 'Chef'],
                ['value' => 'unit_chief', 'label' => "Chef d'unité"],
                ['value' => 'superadmin', 'label' => 'Superadmin'],
            ],
        ];
    }

    private function hasRequiredRole(string $settingKey, string $fallback): bool
    {
        $required = (string) ($this->settingService->get($settingKey, 'retro') ?: $fallback);
        return Role::fromString(AuthSession::getRole())->hasAccess(Role::fromString($required));
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (string) $value;
        return $value !== '' ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = (string) $value;
        return $value !== '' ? $value : null;
    }
}
