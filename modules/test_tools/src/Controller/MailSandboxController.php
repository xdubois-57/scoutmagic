<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\TestTools\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Modules\TestTools\Service\MailSandboxService;
use Twig\Environment;

/**
 * `/test-tools/mail-sandbox` (`role_min: superadmin`) — the captured
 * messages, and the switch that decides whether mail leaves the server at
 * all (ARCHITECTURE.md §8.61).
 */
class MailSandboxController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        protected Environment $twig,
        private MailSandboxService $sandboxService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $page = max(1, (int) $request->getQuery('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;
        $total = $this->sandboxService->count();

        return $this->render('@test_tools/mail_sandbox.html.twig', [
            'armed' => $this->sandboxService->armed(),
            'emails' => $this->sandboxService->page(self::PAGE_SIZE, $offset),
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'page_count' => (int) ceil($total / self::PAGE_SIZE),
        ]);
    }

    /**
     * `POST /test-tools/mail-sandbox/capture` — arm or disarm.
     *
     * The switch reads the submitted state rather than toggling blind, so
     * two tabs open on the page cannot flip each other's intent.
     *
     * @param array<string, string> $params
     */
    public function toggleCapture(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton de sécurité invalide.', 400);
        }

        $this->sandboxService->setArmed(
            (string) $request->getBody('armed', '0') === '1',
            AuthSession::getUserAccountId()
        );

        return $this->redirect('/test-tools/mail-sandbox');
    }
}
