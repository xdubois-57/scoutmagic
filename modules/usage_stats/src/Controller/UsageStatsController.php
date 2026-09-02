<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Modules\UsageStats\Service\UsageStatsService;
use Twig\Environment;

/**
 * The three screens of Configuration > Fréquentation, all `superadmin`
 * like every other page of that menu.
 *
 * They are three routes rather than one page with tabs in the query
 * string, because they answer three different questions and a chief comes
 * back to one of them: « le site sert-il », « qu'est-ce qui sert », « le
 * détail par page ». The month, on the other hand, is a query parameter
 * shared by all three — switching screens keeps the month you were
 * reading.
 *
 * **No state is remembered between visits**: the month and the audience
 * filter live in the query string and nowhere else, so the page never
 * opens on somebody else's stale filter and it sets no cookie. Same
 * stance as the support dashboard (ARCHITECTURE.md §8.49), and here it is
 * also the point of the whole module.
 */
class UsageStatsController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private UsageStatsService $service
    ) {
    }

    /**
     * GET /config/usage — « le site sert-il ? »
     *
     * @param array<string, string> $params
     */
    public function overview(Request $request, array $params): Response
    {
        $month = $this->month($request);

        return $this->render('@usage_stats/overview.html.twig', [
            'overview' => $this->service->overview($month),
            'months' => $this->service->availableMonths(),
            'selected_month' => $month,
        ]);
    }

    /**
     * GET /config/usage/modules — « qu'est-ce qui sert, et qu'est-ce qui
     * ne sert jamais ? »
     *
     * @param array<string, string> $params
     */
    public function modules(Request $request, array $params): Response
    {
        $month = $this->month($request);

        return $this->render('@usage_stats/modules.html.twig', [
            'report' => $this->service->modules($month),
            'months' => $this->service->availableMonths(),
            'selected_month' => $month,
        ]);
    }

    /**
     * GET /config/usage/pages — the detail, per route pattern.
     *
     * @param array<string, string> $params
     */
    public function pages(Request $request, array $params): Response
    {
        $month = $this->month($request);
        $audience = $request->getQuery('audience');

        return $this->render('@usage_stats/pages.html.twig', [
            'report' => $this->service->pages($month, is_string($audience) && $audience !== '' ? $audience : null),
            'months' => $this->service->availableMonths(),
            'selected_month' => $month,
        ]);
    }

    private function month(Request $request): string
    {
        $requested = $request->getQuery('month');

        return $this->service->resolveMonth(is_string($requested) ? $requested : null);
    }
}
