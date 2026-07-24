<?php

declare(strict_types=1);

namespace Modules\Finance\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
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
            'overview' => $this->overviewService->buildOverview(),
            'focus_source' => (string) $request->getQuery('source', ''),
            'focus_id' => (int) $request->getQuery('id', 0),
        ]);
    }
}
