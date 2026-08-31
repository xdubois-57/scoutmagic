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
        private TicketIntakeService $intakeService
    ) {
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
