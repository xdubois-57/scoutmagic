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

        // Whoever the message was written to, the request is the same one:
        // « stop writing to me ». It is answered on every table that could
        // write to this address again, never only on the one the link
        // happened to come from — a person who unsubscribes as a list's
        // contact and then receives a publipostage has not been heard.
        // The module's own suppression list first, WHOEVER asked — a member
        // included. A member's own unsubscribe deactivates their
        // member_emails row, which stops member mail and nothing else: a
        // chief's Excel file could carry that same address into a contact
        // group tomorrow, and that path does not consult member_emails.
        // What the person asked us to stop writing to is the ADDRESS, so the
        // record of the request has to live somewhere that is not attached
        // to their membership.
        //
        // The member freeze does not read this table (MassMailService::
        // eligibleAddressesOfMember()): a member's address is governed by
        // the member_emails row deactivated below, which « Réactiver » can
        // undo — nothing ever removes a row from this one.
        $this->suppressedAddressRepository->suppress($delivery->emailAddress);

        // Then every contact group's rows holding this address, in every
        // list, flagged in one statement — which is what keeps them visible
        // and greyed on the screen rather than silently skipped. After the
        // suppression, so a failure here cannot lose the request itself:
        // the freeze reads the suppression.
        $this->listBlockService?->unsubscribeEverywhere($delivery->emailAddress);

        if ($delivery->memberEmailId !== null) {
            $this->memberEmailService->unsubscribe($delivery->memberEmailId);
        }

        return $this->render('@mass_mail/unsubscribe.html.twig', ['state' => 'done']);
    }
}
