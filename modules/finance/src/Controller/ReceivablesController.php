<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\Role;
use Modules\Finance\Service\ReceivablesOverviewService;
use Twig\Environment;

class ReceivablesController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ReceivablesOverviewService $overviewService
    ) {
    }

    /**
     * GET /finance/receivables — "Paiements attendus" reconciliation page.
     * ?source=news&id={form_id} pre-expands the matching level-1/level-2
     * accordion sections (handled client-side — the ids are simply passed
     * through to the template).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        return $this->render('@finance/receivables.html.twig', [
            // role_min: intendant only proves the caller may open the page —
            // which accounts' receivables they may actually see is a
            // per-account decision (role_min_view), made in the service.
            'overview' => $this->overviewService->buildOverview(Role::fromString(AuthSession::getRole())),
            'focus_source' => (string) $request->getQuery('source', ''),
            'focus_id' => (int) $request->getQuery('id', 0),
        ]);
    }
}
