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
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ListBlockService;
use Twig\Environment;

/**
 * The mass-mail one-click unsubscribe flow (module addendum, RFC 8058).
 * Both actions are `role_min: public`, no session — same justified
 * exception as Core\Http\Controller\WebhookController (a machine caller,
 * here a mailbox provider, has no session to bind a CSRF token to).
 * Authentication is entirely the token of one MESSAGE — one address
 * (Repository\DeliveryRepository::verifyUnsubscribeToken(), issue #999) —
 * never a bare message id trusted from the URL alone. `{id}` in the two
 * routes is that message's id; the routes kept their form.
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
        private DeliveryRepository $deliveryRepository,
        private MemberEmailService $memberEmailService,
        private SuppressedAddressRepository $suppressedAddressRepository,
        /**
         * Nullable so the tests that predate contact groups keep their
         * constructor call; the composition roots always pass it.
         */
        private ?ListBlockService $listBlockService = null
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

        $delivery = ($id > 0 && $token !== '')
            ? $this->deliveryRepository->verifyUnsubscribeToken($id, $token)
            : null;

        return $this->render('@mass_mail/unsubscribe.html.twig', [
            'state' => $delivery !== null ? 'confirm' : 'invalid',
            'delivery_id' => $id,
            'token' => $token,
        ]);
    }

    /**
     * POST /mass-mail/unsubscribe/{id} — the one-click target. Idempotent:
     * a mailbox prefetch, the confirm page's own submit, and a manual
     * resubmit may all reach this for the same message, and every one
     * of them must end up in the same "unsubscribed" state without error.
     *
     * @param array<string, string> $params
     */
    public function unsubscribe(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $queryToken = (string) $request->getQuery('token', '');
        $token = $queryToken !== '' ? $queryToken : (string) $request->getBody('token', '');

        $delivery = ($id > 0 && $token !== '')
            ? $this->deliveryRepository->verifyUnsubscribeToken($id, $token)
            : null;
        if ($delivery === null) {
            return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'invalid']);
        }

        // The inverse of reactivation uses the same transaction and lock
        // order. Neither a partial failure nor an overlapping restore can
        // leave different consent decisions in the three stores.
        $notifications = [];
        $defer = static function (callable $notify) use (&$notifications): void {
            $notifications[] = $notify;
        };
        $this->suppressedAddressRepository->atomically(function () use ($delivery, $defer): void {
            $this->suppressedAddressRepository->suppress($delivery->emailAddress);
            $this->listBlockService?->unsubscribeEverywhere($delivery->emailAddress);
            if ($delivery->memberEmailId !== null) {
                $this->memberEmailService->unsubscribe($delivery->memberEmailId, $defer);
            }
        });
        foreach ($notifications as $notify) {
            $notify();
        }

        return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'done']);
    }
}
