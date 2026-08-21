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
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Twig\Environment;

/**
 * `POST /api/statistics` (`role_min: public`) — where reporting
 * installations send their daily usage report (ARCHITECTURE.md §8.45).
 *
 * **The second deliberate CSRF exception in this codebase**, after
 * `POST /api/webhook/github`, and for the same reason: the caller is a
 * machine with no session, so there is no session-bound token to carry and
 * CSRF is not the threat being defended against. Authentication is a bearer
 * secret checked against a `password_hash()` — see SECURITY.md §4.
 *
 * The controller orchestrates only: transport check, header and body
 * extraction, then one call into the service, then a status. **The response
 * never echoes the payload** and never distinguishes an unknown
 * installation from a wrong secret — a caller learns the status code and
 * nothing else.
 */
class StatisticsIntakeController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private StatisticsIntakeService $intakeService
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
            self::isSecureTransport($request)
        );

        if ($result->accepted) {
            // A minimal 204: nothing to say back, and nothing about the
            // stored state worth revealing to an anonymous caller.
            return (new Response('', 204))->setHeader('Content-Type', 'application/json');
        }

        return $this->json(['status' => 'rejected'], $result->statusCode);
    }

    /**
     * Same HTTPS-detection convention as Core\Security\SessionManager and
     * SetupController::resolveDefaultBaseUrl().
     */
    private static function isSecureTransport(Request $request): bool
    {
        $https = $request->getServer('HTTPS');

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off')
            || (int) $request->getServer('SERVER_PORT', 0) === 443;
    }
}
