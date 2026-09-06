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
use Modules\SupportDashboard\Service\TriageExtractService;
use Twig\Environment;

/**
 * `POST /api/support/tickets/{reference}/triage-extract` (`role_min:
 * public`) — where the automated GitHub triage fetches the anonymised
 * extract of a ticket's archive (ARCHITECTURE.md §8.49sexies).
 *
 * **The seventh deliberate CSRF exception in this codebase** (SECURITY.md
 * §4): the caller is a GitHub Actions runner with no session, and what
 * authenticates it is the receiver's own triage token, presented as
 * `Authorization: Bearer` and compared constant-time against a stored
 * SHA-256 (`Service\TriageTokenService`).
 *
 * **A `POST` that answers a file**, for the reasons `mail-probes` is a
 * POST that answers a list: the call has a side effect — it binds the
 * reference to the issue that cites it — and the issue number travels in
 * a body rather than in a query string that proxies and access logs
 * keep. It is also the one route of this module that answers bytes
 * outside `/files/{id}`, which SECURITY.md §6 records as the exception it
 * is: what leaves is a derivative built for the call and never stored,
 * so there is no file for `FileAccessGuard` to guard.
 *
 * The controller orchestrates only. Every refusal is the same bare 403.
 */
class TriageExtractController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private TriageExtractService $service
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function serve(Request $request, array $params): Response
    {
        $result = $this->service->serve(
            (string) ($params['reference'] ?? ''),
            $request->getRawBody(),
            (string) $request->getServer('HTTP_AUTHORIZATION', ''),
            (string) $request->getServer('REMOTE_ADDR', ''),
            $request->isHttps(),
            new \DateTimeImmutable()
        );

        if (!$result->accepted) {
            return $this->json(['status' => 'rejected'], 403);
        }

        return (new Response($result->bytes))
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="triage-extract-' . $result->reference . '.zip"'
            )
            ->setHeader('Content-Length', (string) strlen($result->bytes))
            // Built for one call and never to be kept by anything on the
            // way: not a proxy, not the runner's HTTP cache.
            ->setHeader('Cache-Control', 'no-store');
    }
}
