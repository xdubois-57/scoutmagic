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
use Modules\TestTools\Service\MailSandboxFilters;
use Modules\TestTools\Service\MailSandboxService;
use Twig\Environment;

/**
 * `/test-tools/mail-sandbox` (`role_min: superadmin`) — the captured
 * messages, and the switch that decides whether mail leaves the server at
 * all (ARCHITECTURE.md §8.63).
 */
class MailSandboxController extends AbstractController
{
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
        $filters = MailSandboxFilters::fromQuery($request->getQueryAll());

        // The body scan is opt-in and bounded: it decrypts each candidate
        // to read it, so it narrows the id set the ordinary query then
        // pages over rather than becoming a second listing path.
        $ids = $filters->scansBodies()
            ? $this->sandboxService->searchBodies($filters->subject, MailSandboxFilters::BODY_SEARCH_BOUND)
            : null;

        $total = $this->sandboxService->count(
            $filters->scansBodies() ? null : $filters->subjectFilter(),
            $filters->recipientFilter(),
            $ids
        );

        return $this->render('@test_tools/mail_sandbox.html.twig', [
            'breadcrumb_trail' => [
                ['label' => 'Outils de test', 'url' => '/test-tools'],
            ],
            'armed' => $this->sandboxService->armed(),
            'filters' => $filters,
            'emails' => $this->sandboxService->page(
                MailSandboxFilters::PAGE_SIZE,
                $filters->offset(),
                $filters->scansBodies() ? null : $filters->subjectFilter(),
                $filters->recipientFilter(),
                $ids
            ),
            'total' => $total,
            'page_size' => MailSandboxFilters::PAGE_SIZE,
            'page_count' => max(1, (int) ceil($total / MailSandboxFilters::PAGE_SIZE)),
            'body_search_bound' => MailSandboxFilters::BODY_SEARCH_BOUND,
            'confirmation_word' => MailSandboxService::CONFIRMATION_WORD,
        ]);
    }

    /**
     * `GET /test-tools/mail-sandbox/{id}` — one captured message, in five
     * tabs.
     *
     * The HTML half is handed to the template as a plain string and
     * rendered into a sandboxed iframe's `srcdoc`; it is never written into
     * the page itself. Twig's auto-escaping handles the attribute.
     *
     * @param array<string, string> $params
     */
    public function detail(Request $request, array $params): Response
    {
        $email = $this->sandboxService->find((int) ($params['id'] ?? 0));
        if ($email === null) {
            return new Response('', 404);
        }

        return $this->render('@test_tools/mail_detail.html.twig', [
            'breadcrumb_trail' => [
                ['label' => 'Outils de test', 'url' => '/test-tools'],
                ['label' => 'Bac à sable e-mail', 'url' => '/test-tools/mail-sandbox'],
            ],
            'email' => $email,
            'html_body' => $this->sandboxService->htmlBody($email),
            'text_body' => $this->sandboxService->textBody($email),
            'headers' => $this->sandboxService->headers($email),
            'raw_message' => $this->sandboxService->rawMessage($email),
        ]);
    }

    /**
     * `POST /test-tools/mail-sandbox/empty` — the danger zone: delete every
     * captured message and every encrypted file behind them.
     *
     * The operator has to type the confirmation word, and it is compared
     * **here**, server-side, exactly like every other destructive action in
     * this codebase (ARCHITECTURE.md §8.18). A JavaScript check may only
     * gate the button's disabled state — a confirmation a caller can skip
     * is not a control at all.
     *
     * @param array<string, string> $params
     */
    public function empty(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/test-tools/mail-sandbox')) !== null) {
            return $guard;
        }

        if ((string) $request->getBody('confirmation', '') !== MailSandboxService::CONFIRMATION_WORD) {
            return new Response('Mot de confirmation incorrect.', 400);
        }

        $this->sandboxService->empty(AuthSession::getUserAccountId());

        return $this->redirect('/test-tools/mail-sandbox');
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
        if (($guard = $this->guardCsrf($request, '/test-tools/mail-sandbox')) !== null) {
            return $guard;
        }

        $this->sandboxService->setArmed(
            (string) $request->getBody('armed', '0') === '1',
            AuthSession::getUserAccountId()
        );

        return $this->redirect('/test-tools/mail-sandbox');
    }
}
