<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\SupportDashboard\Service\MailProbeService;
use Modules\SupportDashboard\Service\TicketArchiveIntakeService;
use Modules\SupportDashboard\Service\TicketIntakeService;
use Modules\SupportDashboard\TicketCategory;
use Twig\Environment;

/**
 * `POST /api/support/tickets` (`role_min: public`) — where an
 * installation sends a support ticket (roadmap IT-23).
 *
 * **The fourth deliberate CSRF exception in this codebase** (SECURITY.md
 * §4), for the same reason as the statistics intake beside it: the caller
 * is a machine with no session, so there is no session-bound token to
 * carry and CSRF is not the threat. Authentication is the installation's
 * own bearer secret, verified against the `password_hash()` the
 * statistics intake stored.
 *
 * The controller orchestrates only: header and body extraction, one call
 * into the service, then a status. **Every answer carries the closed list
 * of categories**, which is how an instance renders a picker it was not
 * shipped with — including on a refusal, where it is exactly what the
 * caller needs to correct itself.
 */
class TicketIntakeController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TicketIntakeService $intakeService,
        private ?TicketArchiveIntakeService $archiveService = null,
        private ?MailProbeService $probeService = null
    ) {
    }

    /**
     * POST /api/support/mail-probes — the addresses an installation
     * should send a diagnostic mail to, and the key that will identify it
     * (roadmap IT-27).
     *
     * **POST rather than GET, though it reads like a lookup**: the call
     * writes one row per mailbox and the identity travels in a body, not
     * in a query string that proxies and access logs keep. It is also
     * what lets the instance side reuse the one transport it already has.
     *
     * Nothing is hard-coded: the addresses come from `inbound_mail` as it
     * is configured right now, so adding or removing a box asks nothing
     * of any installation.
     *
     * @param array<string, string> $params
     */
    public function mailProbes(Request $request, array $params): Response
    {
        if ($this->probeService === null) {
            return $this->json(['status' => MailProbeService::STATUS_REJECTED], 403);
        }

        $payload = json_decode($request->getRawBody(), true);
        $installationId = is_array($payload) && is_string($payload['installation_id'] ?? null)
            ? (string) $payload['installation_id']
            : '';

        $answer = $this->probeService->issueFor(
            $installationId,
            (string) $request->getServer('HTTP_AUTHORIZATION', ''),
            $request->isHttps(),
            new \DateTimeImmutable()
        );

        // A refusal to authenticate says only that, with the same 403 the
        // other machine routes of this module answer; everything else is
        // a complete 200 answer the instance has a screen to render.
        return $this->json(
            $answer,
            $answer['status'] === MailProbeService::STATUS_REJECTED ? 403 : 200
        );
    }

    /**
     * POST /api/support/tickets/{reference}/archive — the diagnostic
     * archive of a ticket that already exists (roadmap IT-26).
     *
     * A separate call from the ticket, deliberately: an upload that times
     * out must not take the report down with it, so the ticket is created
     * first and the archive either joins it or does not.
     *
     * @param array<string, string> $params
     */
    public function receiveArchive(Request $request, array $params): Response
    {
        if ($this->archiveService === null) {
            return $this->json(['status' => 'rejected'], 403);
        }

        $result = $this->archiveService->receive(
            (string) ($params['reference'] ?? ''),
            $request->getRawBody(),
            (string) $request->getServer('HTTP_AUTHORIZATION', ''),
            (string) $request->getServer('REMOTE_ADDR', ''),
            $request->isHttps()
        );

        if ($result->accepted) {
            return $this->json(['status' => 'accepted'], 200);
        }

        if ($result->statusCode === 403) {
            return $this->json(['status' => 'rejected'], 403);
        }

        return $this->json(['status' => 'refused', 'reason' => $result->rejectionReason], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function receive(Request $request, array $params): Response
    {
        $result = $this->intakeService->receive(
            $request->getRawBody(),
            (string) $request->getServer('HTTP_AUTHORIZATION', ''),
            (string) $request->getServer('REMOTE_ADDR', ''),
            $request->isHttps()
        );

        if ($result->accepted) {
            return $this->json([
                'status' => 'accepted',
                'ticket_reference' => $result->ticketReference,
                'categories' => TicketCategory::published(),
            ], 200);
        }

        // A 403 says only that: no reason, no category list, nothing an
        // unauthenticated caller could learn from. Everything else is a
        // 200 carrying what went wrong — a retry would not help, and the
        // instance has a screen to say so on.
        if ($result->statusCode === 403) {
            return $this->json(['status' => 'rejected'], 403);
        }

        return $this->json([
            'status' => 'refused',
            'reason' => $result->rejectionReason,
            'categories' => TicketCategory::published(),
        ], 200);
    }
}
