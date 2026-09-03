<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\MemberEmailService;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Twig\Environment;

/**
 * The mass-mail one-click unsubscribe flow (module addendum, RFC 8058).
 * Both actions are `role_min: public`, no session — same justified
 * exception as Core\Http\Controller\WebhookController (a machine caller,
 * here a mailbox provider, has no session to bind a CSRF token to).
 * Authentication is entirely the per-recipient token
 * (Repository\RecipientRepository::verifyUnsubscribeToken()) — never a
 * bare recipient id trusted from the URL alone.
 *
 * show() (GET) is read-only by design — a bare GET must never perform the
 * unsubscribe itself, since email clients/antivirus routinely prefetch
 * every link in an email. unsubscribe() (POST) is the only action that
 * mutates state, shared by two callers: the confirm page's own submit
 * button (a real browser form post, token carried as a hidden field) and
 * a mailbox provider's List-Unsubscribe-Post one-click request (posts to
 * the exact List-Unsubscribe header URL, token still in the query
 * string) — token resolution checks the query string first, then the
 * body, to cover both.
 */
class UnsubscribeController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private RecipientRepository $recipientRepository,
        private MemberEmailService $memberEmailService,
        private SuppressedAddressRepository $suppressedAddressRepository
    ) {
    }

    /**
     * GET /mass-mail/unsubscribe/{id} — the confirmation page, reached via
     * the footer link and the List-Unsubscribe header's URL alike.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $token = (string) $request->getQuery('token', '');

        $recipient = ($id > 0 && $token !== '')
            ? $this->recipientRepository->verifyUnsubscribeToken($id, $token)
            : null;

        return $this->render('@mass_mail/unsubscribe.html.twig', [
            'state' => $recipient !== null ? 'confirm' : 'invalid',
            'recipient_id' => $id,
            'token' => $token,
        ]);
    }

    /**
     * POST /mass-mail/unsubscribe/{id} — the one-click target. Idempotent:
     * a mailbox prefetch, the confirm page's own submit, and a manual
     * resubmit may all reach this for the same recipient, and every one
     * of them must end up in the same "unsubscribed" state without error.
     *
     * @param array<string, string> $params
     */
    public function unsubscribe(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $queryToken = (string) $request->getQuery('token', '');
        $token = $queryToken !== '' ? $queryToken : (string) $request->getBody('token', '');

        $recipient = ($id > 0 && $token !== '')
            ? $this->recipientRepository->verifyUnsubscribeToken($id, $token)
            : null;
        if ($recipient === null) {
            return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'invalid']);
        }

        if ($recipient->memberEmailId !== null) {
            $this->memberEmailService->unsubscribe($recipient->memberEmailId);
        } elseif ($recipient->memberId === null && $recipient->emailAddress !== null) {
            // External mail-merge recipient — no member_emails row to flip,
            // so the address lands on the module's own suppression list,
            // honored by every future mail-merge freeze.
            $this->suppressedAddressRepository->suppress($recipient->emailAddress);
        } else {
            // A member recipient frozen without a member_email_id (the
            // defensive "no usable address" error row) never got a token,
            // so this is unreachable in practice — fail gracefully anyway.
            return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'invalid']);
        }

        return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'done']);
    }
}
